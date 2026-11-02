<?php

namespace App\Modules\Payments\Services;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnRequest;
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
 * record of the transaction (reconcile()) and can never be sent again blindly. Requested by an
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
            $payment = Payment::where('order_id', $order->id)->whereNull('duplicate_of_payment_id')->orderBy('id')->lockForUpdate()->get()
                ->first(fn (Payment $p) => $p->refundableMinor() > 0);

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
     * Settle an "unknown" refund from the provider's record of the payment's transaction: the
     * provider's refunded total tells whether this refund was paid out (succeeded) or not
     * (failed: safe to send again). When it cannot tell (other refunds in flight, partial
     * difference, provider unreachable) the refund stays unknown for a person to check.
     *
     * @return array{refund: Refund, outcome: string}
     */
    public function reconcile(Refund $refund, ?int $adminId = null): array
    {
        $refund = $refund->fresh();

        if ($refund->status !== Refund::UNKNOWN || !$refund->payment_id) {
            return ['refund' => $refund, 'outcome' => 'not_unknown'];
        }

        $payment = Payment::findOrFail($refund->payment_id);
        $gateway = $this->gateways->for($payment->provider);

        if (!$gateway instanceof PaymobGateway || !$payment->provider_transaction_id) {
            return ['refund' => $refund, 'outcome' => 'cannot_check'];
        }

        try {
            $transaction = $gateway->fetchTransaction($payment->provider_transaction_id);
        } catch (PaymentGatewayException) {
            return ['refund' => $refund, 'outcome' => 'provider_unreachable'];
        }

        if ($transaction?->refundedAmountProviderUnits === null) {
            return ['refund' => $refund, 'outcome' => 'cannot_check'];
        }

        $providerRefundedMinor = intdiv($transaction->refundedAmountProviderUnits, max(1, (int) config('services.paymob.amount_factor', 1)));

        return DB::transaction(function () use ($refund, $payment, $providerRefundedMinor, $adminId) {
            Order::lockForUpdate()->find($refund->order_id);
            $refund = Refund::lockForUpdate()->findOrFail($refund->id);
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);

            if ($refund->status !== Refund::UNKNOWN) {
                return ['refund' => $refund, 'outcome' => 'not_unknown'];
            }

            $recordedMinor = Money::toMinor($payment->refunded_amount);
            $amountMinor = Money::toMinor($refund->amount);
            $note = ['reconciled_at' => now()->toIso8601String(), 'provider_refunded' => Money::fromMinor($providerRefundedMinor), 'recorded_refunded' => Money::fromMinor($recordedMinor)];

            if ($this->inFlightMinor($payment, $refund->id) > 0) {
                $refund->update(['metadata' => array_merge($refund->metadata ?? [], $note + ['reconcile' => 'other refunds in flight'])]);

                return ['refund' => $refund, 'outcome' => 'ambiguous'];
            }

            if ($providerRefundedMinor >= $recordedMinor + $amountMinor) {
                $refund->update(['metadata' => array_merge($refund->metadata ?? [], $note + ['reconcile' => 'paid out'])]);

                return ['refund' => $this->markSucceeded($refund, null, $adminId), 'outcome' => 'succeeded'];
            }

            if ($providerRefundedMinor <= $recordedMinor) {
                $refund->update(['status' => Refund::FAILED, 'failure_reason' => 'The provider shows no refund for this request; it can be sent again.',
                    'metadata' => array_merge($refund->metadata ?? [], $note + ['reconcile' => 'not paid out'])]);

                return ['refund' => $refund->fresh(), 'outcome' => 'failed'];
            }

            $refund->update(['metadata' => array_merge($refund->metadata ?? [], $note + ['reconcile' => 'partial difference'])]);

            return ['refund' => $refund, 'outcome' => 'ambiguous'];
        }, 3);
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
