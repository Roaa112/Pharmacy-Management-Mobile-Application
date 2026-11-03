<?php

namespace App\Modules\Payments\Services;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Modules\Payments\Gateways\GatewayNotification;
use App\Modules\Payments\Gateways\GatewayRefundResult;
use App\Modules\Payments\Gateways\GatewayRegistry;
use App\Modules\Payments\Gateways\PaymentGatewayException;
use App\Modules\Payments\Gateways\PaymobGateway;
use App\Support\DocumentNumber;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Refund lifecycle: requested (pending) -> processing (sent to the gateway) -> succeeded / failed,
 * or canceled before processing. A gateway request without a usable answer (timeout, provider
 * error) becomes "unknown": it may have been paid out, so it is reconciled with the provider's
 * records (reconcile()) and can never be sent again blindly: it becomes "failed" (resendable)
 * only when the provider declines it, or when a person confirms the provider never paid it
 * (resolveUnknown()). Requested by an
 * accepted return, a canceled paid order, a duplicate capture or a provider-side refund;
 * processed by an admin with the "refunds" permission — a different admin than the requester
 * when pharmacy.refund_dual_control is on.
 *
 * Invariants (under the order and payment locks): succeeded + open refunds of a payment never
 * exceed what was received; only succeeded refunds change the payment (refunded_amount, status)
 * and the order (refund_status). Lock order: order, refund, payment.
 */
class RefundService
{
    public function __construct(private PaymentService $payments, private GatewayRegistry $gateways)
    {
    }

    /**
     * The refund approved on a return, against the payment that actually received the money.
     * Nothing can be refunded on an order whose money was never received (unpaid COD, unpaid
     * online payment): that raises DomainException and the caller's transaction rolls back.
     */
    public function requestForReturn(ReturnRequest $return, int $amountMinor, ?int $adminId): ?Refund
    {
        if ($amountMinor <= 0) {
            return null;
        }

        return DB::transaction(function () use ($return, $amountMinor, $adminId) {
            $order = Order::lockForUpdate()->findOrFail($return->order_id);
            $payment = $this->payments->lockOrderPayments($order->id)
                ->first(fn (Payment $p) => $p->duplicate_of_payment_id === null && $p->refundableMinor() > 0);

            $available = $payment ? $payment->refundableMinor() - $this->openMinor($payment) : 0;
            if ($amountMinor > $available) {
                throw new DomainException($payment
                    ? 'Only '.number_format(Money::fromMinor(max(0, $available)), 2).' of the money received for this order can still be refunded.'
                    : 'No payment has been received for this order, so nothing can be refunded.');
            }

            return DocumentNumber::create(Refund::class, 'RF', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'return_request_id' => $return->id,
                'amount' => Money::fromMinor($amountMinor),
                'currency' => $order->currency_code,
                'method' => $this->methodFor($payment),
                'status' => Refund::PENDING,
                'reason' => "Return #{$return->id}",
                'requested_by' => $adminId,
            ]);
        });
    }

    public function requestForPayment(Payment $payment, int $amountMinor, string $reason, ?int $adminId, ?string $method = null): ?Refund
    {
        if ($amountMinor <= 0) {
            return null;
        }

        return DB::transaction(function () use ($payment, $amountMinor, $reason, $adminId, $method) {
            Order::lockForUpdate()->find($payment->order_id);
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $available = $payment->refundableMinor() - $this->openMinor($payment);

            if ($amountMinor > $available) {
                throw new DomainException('The refund is larger than what is left to refund on this payment ('.Money::fromMinor(max(0, $available)).').');
            }

            return DocumentNumber::create(Refund::class, 'RF', [
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'amount' => Money::fromMinor($amountMinor),
                'currency' => $payment->currency,
                'method' => $method ?? $this->methodFor($payment),
                'status' => Refund::PENDING,
                'reason' => Str::limit($reason, 250),
                'requested_by' => $adminId,
            ]);
        });
    }

    /**
     * A refund the provider already made (from its own dashboard): recorded as requested and
     * succeeded, so our books match the provider. Open requests do not reduce what can be
     * recorded here (the money has moved whatever we planned); they are capped by what is left
     * when they are paid out (process()). More than was received cannot be recorded.
     */
    public function recordProviderRefund(Payment $payment, int $amountMinor, ?string $providerRefundId): Refund
    {
        return DB::transaction(function () use ($payment, $amountMinor, $providerRefundId) {
            Order::lockForUpdate()->find($payment->order_id);
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);

            if ($amountMinor <= 0 || $amountMinor > $payment->refundableMinor()) {
                throw new DomainException("The provider refunded {$amountMinor} (minor units) on payment {$payment->internal_reference}, more than is left to refund; check it by hand.");
            }

            $refund = DocumentNumber::create(Refund::class, 'RF', [
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'amount' => Money::fromMinor($amountMinor),
                'currency' => $payment->currency,
                'method' => Refund::METHOD_GATEWAY,
                'status' => Refund::PENDING,
                'reason' => 'Refunded at the provider',
                'provider_refund_id' => $providerRefundId,
            ]);

            return $this->markSucceeded($refund, $providerRefundId, null);
        }, 3);
    }

    /**
     * Gateway refunds marked "processing" whose answer was never recorded (the worker died between
     * sending and recording): after $minutes they become "unknown", to be reconciled with the
     * provider like any unanswered request instead of staying stuck (and blocking the payment's
     * remaining refunds) forever.
     */
    public function flagStaleProcessing(int $minutes = 30): int
    {
        return Refund::where('status', Refund::PROCESSING)->where('method', Refund::METHOD_GATEWAY)
            ->whereNull('provider_refund_id')->whereNull('processed_at')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->update(['status' => Refund::UNKNOWN, 'failure_reason' => 'No answer from the provider was recorded; reconciled with the provider.', 'updated_at' => now()]);
    }

    /**
     * Pay a refund back. Online payments go through the gateway (succeeded, processing until
     * the provider confirms, failed, or unknown when the provider did not answer); cash and
     * manual refunds are recorded with the person's reference.
     */
    public function process(Refund $refund, int $adminId, ?string $externalReference = null): Refund
    {
        $refund = DB::transaction(function () use ($refund, $adminId) {
            Order::lockForUpdate()->find($refund->order_id);
            $refund = Refund::lockForUpdate()->findOrFail($refund->id);

            if ($refund->status === Refund::UNKNOWN) {
                throw new DomainException("Refund {$refund->reference} was sent to the provider without an answer; it may already be paid out. Reconcile it with the provider instead of sending it again.");
            }
            if (!in_array($refund->status, [Refund::PENDING, Refund::FAILED], true)) {
                throw new DomainException("Refund {$refund->reference} is {$refund->status}.");
            }
            if (config('pharmacy.refund_dual_control') && $refund->requested_by && $refund->requested_by === $adminId) {
                throw new DomainException('Separation of duties: this refund was requested by you, so another admin with the refunds permission must pay it out.');
            }

            if ($refund->payment_id) {
                $payment = Payment::lockForUpdate()->findOrFail($refund->payment_id);
                // Other refunds already sent to the provider may have been paid out too.
                $available = $payment->refundableMinor() - $this->inFlightMinor($payment, $refund->id);
                if (Money::toMinor($refund->amount) > $available) {
                    throw new DomainException('Only '.Money::fromMinor(max(0, $available)).' of this payment can still be refunded.');
                }
            }

            if ($refund->method === Refund::METHOD_GATEWAY) {
                $refund->update(['status' => Refund::PROCESSING, 'processed_by' => $adminId,
                    'metadata' => array_merge($refund->metadata ?? [], ['sent_at' => now()->toIso8601String()])]);
            }

            return $refund;
        }, 3);

        if ($refund->method !== Refund::METHOD_GATEWAY) {
            if (blank($externalReference) && $refund->method === Refund::METHOD_MANUAL) {
                throw new DomainException('Enter the reference of the manual refund (transfer number, voucher...).');
            }

            return $this->markSucceeded($refund, null, $adminId, $externalReference);
        }

        // Gateway call outside the transaction (HTTP).
        $payment = Payment::findOrFail($refund->payment_id);
        try {
            $result = $this->gateways->for($payment->provider)->refund($payment, Money::toMinor($refund->amount));
        } catch (PaymentGatewayException $e) {
            $result = new GatewayRefundResult(false, error: $e->getMessage());
        }
        $this->payments->recordInternalEvent($payment, 'refund.requested', ['refund' => $refund->reference, 'result' => $result->raw ?: null, 'error' => $result->error, 'uncertain' => $result->uncertain]);

        if ($result->succeeded) {
            return $this->markSucceeded($refund, $result->providerRefundId, $adminId);
        }

        if ($result->pending) {
            $refund->update(['provider_refund_id' => $result->providerRefundId, 'processed_at' => now()]);

            return $refund->fresh();
        }

        $refund->update([
            'status' => $result->uncertain ? Refund::UNKNOWN : Refund::FAILED,
            'failure_reason' => Str::limit((string) $result->error, 250),
            'processed_at' => now(),
        ]);

        return $refund->fresh();
    }

    /**
     * Settle a refund whose outcome we do not know: "unknown" (sent, no usable answer) or
     * "processing" at the provider (it answered pending). Only provider evidence moves it:
     *  - the provider's own refund transaction (when we have its id): paid out -> succeeded,
     *    declined -> failed (proof it did not happen, so it may be sent again), pending -> unchanged;
     *  - otherwise the payment transaction's refunded total, as positive evidence only: it covers
     *    this refund -> succeeded. A total that does not (yet) show it proves nothing (the
     *    provider may still be processing it): the refund stays unknown, never becomes
     *    resendable by itself, and a person settles it with resolveUnknown().
     * Safe to run repeatedly and concurrently (re-checked under the locks).
     *
     * @return array{refund: Refund, outcome: string}
     */
    public function reconcile(Refund $refund, ?int $adminId = null): array
    {
        $refund = $refund->fresh();

        if (!$this->awaitsProvider($refund) || !$refund->payment_id) {
            return ['refund' => $refund, 'outcome' => 'not_unknown'];
        }

        $payment = Payment::findOrFail($refund->payment_id);
        $gateway = $this->gateways->for($payment->provider);

        if (!$gateway instanceof PaymobGateway || !$payment->provider_transaction_id) {
            return ['refund' => $refund, 'outcome' => 'cannot_check'];
        }

        // Provider calls outside the transaction (HTTP).
        try {
            $own = $refund->provider_refund_id ? $gateway->fetchTransaction($refund->provider_refund_id) : null;
            $transaction = $own ? null : $gateway->fetchTransaction($payment->provider_transaction_id);
        } catch (PaymentGatewayException) {
            return ['refund' => $refund, 'outcome' => 'provider_unreachable'];
        }

        if (!$own && $transaction?->refundedAmountProviderUnits === null) {
            return ['refund' => $refund, 'outcome' => 'cannot_check'];
        }

        return DB::transaction(function () use ($refund, $payment, $own, $transaction, $adminId) {
            Order::lockForUpdate()->find($refund->order_id);
            $refund = Refund::lockForUpdate()->findOrFail($refund->id);
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);

            if (!$this->awaitsProvider($refund)) {
                return ['refund' => $refund, 'outcome' => 'not_unknown']; // settled meanwhile (another admin, a callback)
            }

            $recordedMinor = Money::toMinor($payment->refunded_amount);
            $note = ['reconciled_at' => now()->toIso8601String(), 'recorded_refunded' => Money::fromMinor($recordedMinor)];

            if ($own) {
                $note += ['provider_refund_status' => $own->outcome];

                if (in_array($own->outcome, [GatewayNotification::REFUND, GatewayNotification::VOID, GatewayNotification::SUCCESS], true)) {
                    $refund->update(['metadata' => array_merge($refund->metadata ?? [], $note + ['reconcile' => 'provider refund paid out'])]);

                    return ['refund' => $this->markSucceeded($refund, $refund->provider_refund_id, $adminId), 'outcome' => 'succeeded'];
                }
                if ($own->outcome === GatewayNotification::FAILED) {
                    $refund->update(['status' => Refund::FAILED, 'failure_reason' => Str::limit('The provider declined this refund: '.$own->failureReason, 250),
                        'metadata' => array_merge($refund->metadata ?? [], $note + ['reconcile' => 'provider refund declined'])]);

                    return ['refund' => $refund->fresh(), 'outcome' => 'failed'];
                }
                $refund->update(['metadata' => array_merge($refund->metadata ?? [], $note + ['reconcile' => 'provider still processing'])]);

                return ['refund' => $refund, 'outcome' => 'still_processing'];
            }

            $providerRefundedMinor = intdiv($transaction->refundedAmountProviderUnits, max(1, (int) config('services.paymob.amount_factor', 1)));
            $note += ['provider_refunded' => Money::fromMinor($providerRefundedMinor)];

            if ($this->inFlightMinor($payment, $refund->id) > 0) {
                $refund->update(['metadata' => array_merge($refund->metadata ?? [], $note + ['reconcile' => 'other refunds in flight'])]);

                return ['refund' => $refund, 'outcome' => 'ambiguous'];
            }

            if ($providerRefundedMinor >= $recordedMinor + Money::toMinor($refund->amount)) {
                $refund->update(['metadata' => array_merge($refund->metadata ?? [], $note + ['reconcile' => 'paid out'])]);

                return ['refund' => $this->markSucceeded($refund, null, $adminId), 'outcome' => 'succeeded'];
            }

            // Not visible (yet). Absence is not proof: the provider may still be processing it.
            $partial = $providerRefundedMinor > $recordedMinor;
            $refund->update(['metadata' => array_merge($refund->metadata ?? [], $note + ['reconcile' => $partial ? 'partial difference' : 'not seen at the provider yet'])]);

            return ['refund' => $refund, 'outcome' => $partial ? 'ambiguous' : 'not_seen'];
        }, 3);
    }

    /**
     * A person settles an unknown refund after checking the provider's dashboard: paid out
     * (recorded as succeeded) or not paid out (failed, so it can be sent again or canceled).
     * Never the admin who sent it when dual control is on; the note is kept on the refund.
     */
    public function resolveUnknown(Refund $refund, bool $paidOut, int $adminId, string $note): Refund
    {
        if (trim($note) === '') {
            throw new DomainException('Explain what the provider dashboard shows.');
        }

        return DB::transaction(function () use ($refund, $paidOut, $adminId, $note) {
            Order::lockForUpdate()->find($refund->order_id);
            $refund = Refund::lockForUpdate()->findOrFail($refund->id);

            if ($refund->status !== Refund::UNKNOWN) {
                throw new DomainException("Refund {$refund->reference} is {$refund->status}; only an unknown refund is settled by hand.");
            }
            if (config('pharmacy.refund_dual_control') && $refund->processed_by && $refund->processed_by === $adminId) {
                throw new DomainException('Separation of duties: you sent this refund, so another admin must confirm what the provider shows.');
            }

            $metadata = array_merge($refund->metadata ?? [], ['resolved_by' => $adminId, 'resolved_at' => now()->toIso8601String(),
                'resolution' => $paidOut ? 'paid out' : 'not paid out', 'resolution_note' => Str::limit($note, 250)]);

            if ($paidOut) {
                $refund->update(['metadata' => $metadata]);

                return $this->markSucceeded($refund, null, $adminId, Str::limit($note, 100));
            }

            $refund->update(['status' => Refund::FAILED, 'metadata' => $metadata,
                'failure_reason' => Str::limit('Confirmed not paid out at the provider: '.$note, 250)]);

            return $refund->fresh();
        }, 3);
    }

    /** Sent to the provider without a final answer: unknown, or processing with the provider's refund id. */
    private function awaitsProvider(Refund $refund): bool
    {
        return $refund->status === Refund::UNKNOWN || ($refund->status === Refund::PROCESSING && filled($refund->provider_refund_id));
    }

    public function cancel(Refund $refund, int $adminId, string $reason): Refund
    {
        return DB::transaction(function () use ($refund, $adminId, $reason) {
            $refund = Refund::lockForUpdate()->findOrFail($refund->id);
            if (!in_array($refund->status, [Refund::PENDING, Refund::FAILED], true)) {
                throw new DomainException('Only a pending or failed refund can be canceled.');
            }
            $refund->update(['status' => Refund::CANCELED, 'processed_by' => $adminId, 'processed_at' => now(), 'failure_reason' => Str::limit($reason, 250)]);

            return $refund;
        });
    }

    /** Money went back: update the refund, the payment and the order, exactly once. */
    public function markSucceeded(Refund $refund, ?string $providerRefundId, ?int $adminId, ?string $externalReference = null): Refund
    {
        return DB::transaction(function () use ($refund, $providerRefundId, $adminId, $externalReference) {
            Order::lockForUpdate()->find($refund->order_id);
            $refund = Refund::lockForUpdate()->findOrFail($refund->id);

            if ($refund->status === Refund::SUCCEEDED) {
                return $refund;
            }
            if ($refund->status === Refund::CANCELED) {
                // Canceled while it was being paid out (two admins): nothing may be recorded as paid back.
                throw new DomainException("Refund {$refund->reference} was canceled; record a new refund if money was paid back.");
            }

            $refund->update([
                'status' => Refund::SUCCEEDED,
                'provider_refund_id' => $providerRefundId ?? $refund->provider_refund_id,
                'external_reference' => $externalReference ?? $refund->external_reference,
                'processed_by' => $adminId ?? $refund->processed_by,
                'processed_at' => now(),
                'failure_reason' => null,
            ]);

            if ($refund->payment_id) {
                $payment = Payment::lockForUpdate()->findOrFail($refund->payment_id);
                $refundedMinor = Money::toMinor($payment->refunded_amount) + Money::toMinor($refund->amount);
                $receivedMinor = $payment->isCod() ? Money::toMinor($payment->collected_amount) : $payment->amountMinor();
                $full = $refundedMinor >= $receivedMinor;
                $attributes = ['refunded_amount' => Money::fromMinor($refundedMinor), 'refunded_at' => now()];

                if (in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::PartiallyPaid, PaymentStatus::PartiallyRefunded], true)) {
                    $this->payments->transition($payment, $full ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded, PaymentService::ACTOR_ADMIN, $adminId,
                        "Refund {$refund->reference}", $attributes);
                } else {
                    $payment->update($attributes);
                }
            }

            $this->projectOrder($refund->order_id);

            return $refund->fresh();
        }, 3);
    }

    /**
     * orders.refunded_amount / refund_status from its succeeded refunds. Refunds of a duplicate
     * capture give back money the order never kept, so they do not count as refunds of the order.
     */
    public function projectOrder(int $orderId): void
    {
        $order = Order::findOrFail($orderId);
        $refundedMinor = Money::toMinor(Refund::where('refunds.order_id', $orderId)->where('refunds.status', Refund::SUCCEEDED)
            ->where(fn ($q) => $q->whereNull('refunds.payment_id')->orWhereIn('refunds.payment_id', Payment::where('order_id', $orderId)->whereNull('duplicate_of_payment_id')->select('id')))
            ->sum('refunds.amount'));

        $order->forceFill([
            'refunded_amount' => Money::fromMinor($refundedMinor),
            'refund_status' => $refundedMinor <= 0 ? 'none' : ($refundedMinor >= Money::toMinor($order->total_price) ? 'full' : 'partial'),
        ])->save();
    }

    private function methodFor(?Payment $payment): string
    {
        return match (true) {
            $payment?->isOnline() => Refund::METHOD_GATEWAY,
            $payment?->isCod() => Refund::METHOD_CASH,
            default => Refund::METHOD_MANUAL,
        };
    }

    private function openMinor(Payment $payment): int
    {
        return Money::toMinor(Refund::where('payment_id', $payment->id)->whereIn('status', Refund::OPEN)->sum('amount'));
    }

    private function inFlightMinor(Payment $payment, int $exceptRefundId): int
    {
        return Money::toMinor(Refund::where('payment_id', $payment->id)->whereKeyNot($exceptRefundId)->whereIn('status', Refund::IN_FLIGHT)->sum('amount'));
    }
}
