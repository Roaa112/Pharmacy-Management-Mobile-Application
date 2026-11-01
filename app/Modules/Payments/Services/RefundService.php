<?php

namespace App\Modules\Payments\Services;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Modules\Payments\Gateways\GatewayRegistry;
use App\Support\DocumentNumber;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Refund lifecycle: requested (pending) -> processing (sent to the gateway) -> succeeded / failed,
 * or canceled before processing. Requested by an accepted return, a canceled paid order or a
 * provider-side refund; processed by an admin with the "refunds" permission.
 *
 * Invariants: the succeeded and open refunds of a payment never exceed what was received; only
 * succeeded refunds change the payment (refunded_amount, status) and the order (refund_status).
 */
class RefundService
{
    public function __construct(private PaymentService $payments, private GatewayRegistry $gateways)
    {
    }

    /** The refund approved on a return; against the order's received payment. */
    public function requestForReturn(ReturnRequest $return, int $amountMinor, ?int $adminId): ?Refund
    {
        if ($amountMinor <= 0) {
            return null;
        }

        $payment = Payment::where('order_id', $return->order_id)->orderByDesc('id')->get()
            ->first(fn (Payment $p) => $p->refundableMinor() > 0);

        return DB::transaction(function () use ($return, $amountMinor, $adminId, $payment) {
            $order = Order::findOrFail($return->order_id);

            return DocumentNumber::create(Refund::class, 'RF', [
                'order_id' => $order->id,
                'payment_id' => $payment?->id,
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
     * Pay a refund back. Online payments go through the gateway (succeeded, or processing until
     * the provider confirms); cash and manual refunds are recorded with the person's reference.
     */
    public function process(Refund $refund, int $adminId, ?string $externalReference = null): Refund
    {
        $refund = DB::transaction(function () use ($refund, $adminId) {
            $refund = Refund::lockForUpdate()->findOrFail($refund->id);

            if (!in_array($refund->status, [Refund::PENDING, Refund::FAILED], true)) {
                throw new DomainException("Refund {$refund->reference} is {$refund->status}.");
            }

            if ($refund->payment_id) {
                $payment = Payment::lockForUpdate()->findOrFail($refund->payment_id);
                if (Money::toMinor($refund->amount) > $payment->refundableMinor()) {
                    throw new DomainException('Only '.Money::fromMinor($payment->refundableMinor()).' of this payment can still be refunded.');
                }
            }

            if ($refund->method === Refund::METHOD_GATEWAY) {
                $refund->update(['status' => Refund::PROCESSING, 'processed_by' => $adminId]);
            }

            return $refund;
        });

        if ($refund->method !== Refund::METHOD_GATEWAY) {
            if (blank($externalReference) && $refund->method === Refund::METHOD_MANUAL) {
                throw new DomainException('Enter the reference of the manual refund (transfer number, voucher...).');
            }

            return $this->markSucceeded($refund, null, $adminId, $externalReference);
        }

        // Gateway call outside the transaction (HTTP).
        $payment = Payment::findOrFail($refund->payment_id);
        $result = $this->gateways->for($payment->provider)->refund($payment, Money::toMinor($refund->amount));
        $this->payments->recordInternalEvent($payment, 'refund.requested', ['refund' => $refund->reference, 'result' => $result->raw ?: null, 'error' => $result->error]);

        if ($result->succeeded) {
            return $this->markSucceeded($refund, $result->providerRefundId, $adminId);
        }

        if ($result->pending) {
            $refund->update(['provider_refund_id' => $result->providerRefundId, 'processed_at' => now()]);

            return $refund->fresh();
        }

        $refund->update(['status' => Refund::FAILED, 'failure_reason' => Str::limit((string) $result->error, 250), 'processed_at' => now()]);

        return $refund->fresh();
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
        });
    }

    /** orders.refunded_amount / refund_status from its succeeded refunds. */
    public function projectOrder(int $orderId): void
    {
        $order = Order::findOrFail($orderId);
        $refundedMinor = Money::toMinor(Refund::where('order_id', $orderId)->where('status', Refund::SUCCEEDED)->sum('amount'));

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
        return Money::toMinor(Refund::where('payment_id', $payment->id)->whereIn('status', [Refund::PENDING, Refund::PROCESSING])->sum('amount'));
    }
}
