<?php

namespace App\Modules\Payments\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\PaymentStatusHistory;
use App\Models\Refund;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Modules\Payments\Gateways\GatewayNotification;
use App\Modules\Payments\Gateways\GatewayRegistry;
use App\Modules\Payments\Gateways\PaymentGatewayException;
use App\Modules\Payments\Gateways\PaymobGateway;
use App\Support\Money;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The only code that changes a payment, and the only writer of orders.payment_status (a
 * projection of the order's latest payment). Dashboard actions, provider callbacks, courier
 * events and scheduled jobs all come through here.
 *
 * Rules:
 *  - an online payment becomes paid only on verified provider evidence: a valid signature,
 *    optional confirmation from the provider's API, matching amount, currency and reference;
 *    no admin action can mark it paid;
 *  - every provider notification is stored (payment_events) before it is acted on, keyed so a
 *    retried notification is processed once;
 *  - COD collection (customer paid the courier/counter) and COD settlement (the cash reached us,
 *    CodSettlementService) are separate events.
 */
class PaymentService
{
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_CUSTOMER = 'customer';
    public const ACTOR_PROVIDER = 'provider';
    public const ACTOR_SYSTEM = 'system';

    private const MAX_ONLINE_ATTEMPTS = 5;

    public function __construct(private GatewayRegistry $gateways)
    {
    }

    // ------------------------------------------------------------------ creation

    /** Called inside checkout's transaction. */
    public function createForCheckout(Order $order, PaymentMethod $method, ?string $receiptPath = null): Payment
    {
        $provider = $method->provider;
        $status = $receiptPath && $method->requires_receipt ? PaymentStatus::AwaitingVerification : PaymentStatus::Pending;

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'provider' => $provider,
            'internal_reference' => $this->newReference(),
            'amount' => $order->total_price,
            'currency' => $order->currency_code,
            'status' => $status->value,
            'collection_status' => $provider === Payment::PROVIDER_COD ? Payment::COLLECTION_PENDING : null,
            'receipt_path' => $receiptPath,
            'expires_at' => $provider === Payment::PROVIDER_PAYMOB ? now()->addMinutes($this->ttlMinutes()) : null,
        ]);

        $this->history($payment, null, $status, self::ACTOR_CUSTOMER, $order->user_id, 'Created at checkout');
        $this->projectOrder($order->id);

        return $payment;
    }

    /**
     * Start the provider side of an online payment (HTTP; call outside a DB transaction).
     *
     * @return array{status: string, checkout_url: ?string, reference: string, expires_at: ?string, error: ?string}
     */
    public function initiate(Payment $payment): array
    {
        $gateway = $this->gateways->for($payment->provider);

        try {
            $initiation = $gateway->initiate($payment, $payment->order);
        } catch (PaymentGatewayException $e) {
            Log::warning('Payment initiation failed', ['payment' => $payment->internal_reference, 'error' => $e->getMessage()]);
            $this->recordInternalEvent($payment, 'initiation_failed', ['error' => $e->getMessage()], PaymentEvent::RESULT_FAILED, $e->getMessage());
            $this->transition($payment, PaymentStatus::Failed, self::ACTOR_SYSTEM, null, 'Initiation failed', ['failure_reason' => Str::limit($e->getMessage(), 250)]);

            return $this->initiationResult($payment->fresh(), null, $e->getMessage());
        }

        $payment->update([
            'provider_order_id' => $initiation->providerOrderId ?? $payment->provider_order_id,
            'metadata' => array_merge($payment->metadata ?? [], $initiation->metadata, ['checkout_started_at' => now()->toIso8601String()]),
        ]);
        $this->recordInternalEvent($payment, 'initiated', ['provider_order_id' => $initiation->providerOrderId] + $initiation->metadata);

        return $this->initiationResult($payment->fresh(), $initiation->checkoutUrl, null);
    }

    /** A new attempt for an unpaid online order (the previous one failed, expired or was abandoned). */
    public function startNewAttempt(Order $order, ?int $customerId): Payment
    {
        return DB::transaction(function () use ($order, $customerId) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $latest = Payment::where('order_id', $order->id)->lockForUpdate()->latest('id')->first();

            if (!$order->requiresOnlinePayment() || $order->statusEnum() !== OrderStatus::Pending) {
                throw new DomainException(api_trans('payments.not_payable'));
            }
            if ($latest && $latest->status->isSettledPayment()) {
                throw new DomainException(api_trans('payments.already_paid'));
            }
            if ($latest && $latest->attempt >= self::MAX_ONLINE_ATTEMPTS) {
                throw new DomainException(api_trans('payments.not_payable'));
            }
            if (!$this->gateways->isReady(Payment::PROVIDER_PAYMOB)) {
                throw new DomainException(api_trans('payments.unavailable'));
            }

            if ($latest && $latest->status === PaymentStatus::Pending) {
                $this->transition($latest, PaymentStatus::Canceled, self::ACTOR_CUSTOMER, $customerId, 'Superseded by a new attempt');
            }

            $payment = Payment::create([
                'order_id' => $order->id,
                'payment_method_id' => $latest?->payment_method_id ?? $order->payment_method_id,
                'provider' => Payment::PROVIDER_PAYMOB,
                'internal_reference' => $this->newReference(),
                'amount' => $order->total_price,
                'currency' => $order->currency_code,
                'status' => PaymentStatus::Pending->value,
                'attempt' => ($latest?->attempt ?? 0) + 1,
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
            ]);
            $this->history($payment, null, PaymentStatus::Pending, self::ACTOR_CUSTOMER, $customerId, 'Retry');
            $this->projectOrder($order->id);

            return $payment;
        });
    }

    // ------------------------------------------------------------------ provider notifications

    /**
     * Store and apply a provider notification. Returns the stored event; its `result` says what
     * happened (processed, duplicate, rejected, ignored). Throws only on our own failures, so the
     * provider retries.
     */
    public function handleNotification(string $provider, array $query, array $body, bool $isRedirect): PaymentEvent
    {
        $gateway = $this->gateways->for($provider);
        $notification = $gateway->parseNotification($query, $body, $isRedirect);
        $eventType = ($isRedirect ? 'redirect.' : 'webhook.').$notification->outcome;

        if (!$notification->signatureValid) {
            // Kept for investigation (forged or misconfigured); never acted on.
            return PaymentEvent::create([
                'provider' => $provider, 'event_type' => $eventType, 'provider_event_id' => $notification->transactionId,
                'idempotency_key' => "{$provider}:rejected:".Str::uuid(), 'signature_valid' => false,
                'result' => PaymentEvent::RESULT_REJECTED, 'error' => 'Invalid signature', 'payload' => $notification->raw ?: null,
                'processed_at' => now(),
            ]);
        }

        $key = $notification->idempotencyKey($provider);

        try {
            $event = PaymentEvent::create([
                'provider' => $provider, 'event_type' => $eventType, 'provider_event_id' => $notification->transactionId,
                'idempotency_key' => $key, 'signature_valid' => true, 'result' => PaymentEvent::RESULT_RECEIVED,
                'payload' => $notification->raw ?: null,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = PaymentEvent::where('idempotency_key', $key)->firstOrFail();
            // A previous delivery that failed on our side is processed again; anything else is a duplicate.
            if ($existing->result !== PaymentEvent::RESULT_FAILED) {
                return tap($existing, fn ($e) => $e->setAttribute('was_duplicate', true));
            }
            $event = $existing;
        }

        try {
            $this->apply($provider, $gateway, $notification, $event);
        } catch (\Throwable $e) {
            $event->update(['result' => PaymentEvent::RESULT_FAILED, 'error' => Str::limit($e->getMessage(), 1000)]);
            throw $e;
        }

        return $event->fresh();
    }

    private function apply(string $provider, $gateway, GatewayNotification $notification, PaymentEvent $event): void
    {
        $isRefund = in_array($notification->outcome, [GatewayNotification::REFUND, GatewayNotification::VOID], true);
        $payment = $this->findPayment($provider, $notification, $isRefund);

        if (!$payment) {
            $event->update(['result' => PaymentEvent::RESULT_IGNORED, 'error' => 'No matching payment', 'processed_at' => now()]);

            return;
        }

        $event->update(['payment_id' => $payment->id]);

        // Server-side confirmation: act on Paymob's own record of the transaction, not on the message alone.
        if (config('services.paymob.verify_with_api') && $notification->transactionId && $gateway instanceof PaymobGateway) {
            $confirmed = $gateway->fetchTransaction($notification->transactionId);
            if ($confirmed) {
                if ($confirmed->transactionId !== $notification->transactionId || $confirmed->outcome !== $notification->outcome) {
                    $event->update(['result' => PaymentEvent::RESULT_REJECTED, 'error' => 'The provider API does not confirm this notification', 'processed_at' => now()]);

                    return;
                }
                $notification = $confirmed;
            }
        }

        DB::transaction(function () use ($payment, $notification, $event, $isRefund) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);

            if ($isRefund) {
                $this->applyProviderRefund($payment, $notification, $event);

                return;
            }

            if ($error = $this->mismatch($payment, $notification)) {
                $payment->update(['metadata' => array_merge($payment->metadata ?? [], ['needs_review' => $error])]);
                $event->update(['result' => PaymentEvent::RESULT_REJECTED, 'error' => $error, 'processed_at' => now()]);

                return;
            }

            $ids = array_filter(['provider_transaction_id' => $notification->transactionId, 'provider_order_id' => $notification->providerOrderId]);

            match ($notification->outcome) {
                GatewayNotification::SUCCESS => $this->markPaidByProvider($payment, $ids, $event),
                GatewayNotification::PENDING => $payment->update($ids),
                GatewayNotification::FAILED => $payment->status === PaymentStatus::Pending
                    ? $this->transition($payment, PaymentStatus::Failed, self::ACTOR_PROVIDER, null, 'Declined by provider',
                        $ids + ['failure_reason' => Str::limit((string) $notification->failureReason, 250)])
                    : null,
                default => null,
            };

            $event->update(['result' => PaymentEvent::RESULT_PROCESSED, 'processed_at' => now()]);
        });
    }

    private function markPaidByProvider(Payment $payment, array $ids, PaymentEvent $event): void
    {
        if ($payment->status->isSettledPayment()) {
            if ($payment->provider_transaction_id && $payment->provider_transaction_id !== ($ids['provider_transaction_id'] ?? null)) {
                // A second successful transaction for the same payment: money to give back by hand.
                $payment->update(['metadata' => array_merge($payment->metadata ?? [], ['needs_review' => 'Second successful transaction '.$ids['provider_transaction_id']])]);
            }

            return;
        }

        $this->transition($payment, PaymentStatus::Paid, self::ACTOR_PROVIDER, null, 'Verified provider payment', $ids + ['paid_at' => now(), 'failure_reason' => null]);

        // Paid after the order was canceled (late payment): the money must go back.
        $order = Order::find($payment->order_id);
        if ($order?->statusEnum() === OrderStatus::Canceled) {
            app(RefundService::class)->requestForPayment($payment->fresh(), $payment->amountMinor(), 'Paid after the order was canceled', null);
        }
    }

    private function applyProviderRefund(Payment $payment, GatewayNotification $notification, PaymentEvent $event): void
    {
        $refunds = app(RefundService::class);
        $refund = Refund::where('payment_id', $payment->id)->where('provider_refund_id', $notification->transactionId)->first()
            ?? Refund::where('payment_id', $payment->id)->whereIn('status', [Refund::PROCESSING, Refund::PENDING])->where('method', Refund::METHOD_GATEWAY)->orderBy('id')->first();

        $amountMinor = $notification->outcome === GatewayNotification::VOID || $notification->amountProviderUnits === null
            ? $payment->refundableMinor()
            : intdiv($notification->amountProviderUnits, max(1, (int) config('services.paymob.amount_factor', 1)));

        if (!$refund) {
            // Refunded from the provider's own dashboard: record it so our books match.
            $refund = $refunds->requestForPayment($payment, $amountMinor, 'Refunded at the provider', null, Refund::METHOD_GATEWAY);
        }

        if ($refund && $refund->status !== Refund::SUCCEEDED) {
            $refunds->markSucceeded($refund, $notification->transactionId, null);
        }

        $event->update(['result' => PaymentEvent::RESULT_PROCESSED, 'processed_at' => now()]);
    }

    /** Ask the provider about the payment now (dashboard "verify with provider", expiry job). */
    public function refreshFromProvider(Payment $payment): Payment
    {
        $gateway = $this->gateways->for($payment->provider);

        if (!$payment->provider_transaction_id) {
            return $payment;
        }

        $notification = $gateway->fetchTransaction($payment->provider_transaction_id);
        if (!$notification) {
            return $payment;
        }

        $event = PaymentEvent::firstOrCreate(
            ['idempotency_key' => $notification->idempotencyKey($payment->provider)],
            ['provider' => $payment->provider, 'event_type' => 'inquiry.'.$notification->outcome, 'provider_event_id' => $notification->transactionId,
                'signature_valid' => true, 'result' => PaymentEvent::RESULT_RECEIVED, 'payload' => $notification->raw, 'payment_id' => $payment->id],
        );

        if ($event->wasRecentlyCreated || $event->result === PaymentEvent::RESULT_FAILED) {
            $this->apply($payment->provider, $gateway, $notification, $event);
        }

        return $payment->fresh();
    }

    // ------------------------------------------------------------------ cash on delivery

    /**
     * The customer paid cash to the courier or at the counter. Partial collections add up; the
     * payment is paid once everything due has been collected.
     */
    public function recordCodCollection(Payment $payment, int $amountMinor, string $via, string $actorType, ?int $adminId, ?string $note = null): Payment
    {
        return DB::transaction(function () use ($payment, $amountMinor, $via, $actorType, $adminId, $note) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);

            if (!$payment->isCod()) {
                throw new DomainException('Only cash on delivery payments are collected.');
            }
            if (!in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::PartiallyPaid], true)) {
                return $payment; // already collected (e.g. a repeated "delivered" event) or canceled
            }

            $dueMinor = $payment->amountMinor() - Money::toMinor($payment->collected_amount);
            if ($amountMinor <= 0 || $amountMinor > $dueMinor) {
                throw new DomainException('The collected amount must be between 0.01 and the '.Money::fromMinor($dueMinor).' still due.');
            }

            $collectedMinor = Money::toMinor($payment->collected_amount) + $amountMinor;
            $complete = $collectedMinor >= $payment->amountMinor();

            $this->recordInternalEvent($payment, 'cod.collected', ['amount' => Money::fromMinor($amountMinor), 'via' => $via, 'actor' => $actorType, 'admin_id' => $adminId, 'note' => $note]);

            return $this->transition($payment, $complete ? PaymentStatus::Paid : PaymentStatus::PartiallyPaid, $actorType, $adminId, $note ?? "Cash collected ({$via})", [
                'collected_amount' => Money::fromMinor($collectedMinor),
                'collection_status' => $complete ? Payment::COLLECTION_COLLECTED : Payment::COLLECTION_PARTIAL,
                'collected_via' => $via,
                'collected_by' => $adminId,
                'collected_at' => now(),
                'paid_at' => $complete ? now() : null,
            ]);
        });
    }

    /** The courier could not collect (failed delivery). The payment stays due. */
    public function recordCodCollectionFailure(Payment $payment, string $reason, string $actorType, ?int $adminId): void
    {
        if (!$payment->isCod() || !in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::PartiallyPaid], true)) {
            return;
        }

        $payment->update(['collection_status' => Payment::COLLECTION_FAILED, 'failure_reason' => Str::limit($reason, 250)]);
        $this->recordInternalEvent($payment, 'cod.collection_failed', ['reason' => $reason, 'actor' => $actorType, 'admin_id' => $adminId]);
    }

    /** Legacy manual transfers (ZainCash / Qi Card orders placed before removal): an admin checks the receipt. */
    public function verifyManualReceipt(Payment $payment, bool $approve, int $adminId): Payment
    {
        if ($payment->provider !== Payment::PROVIDER_MANUAL_TRANSFER) {
            throw new DomainException('Only legacy manual-transfer payments can be verified by hand. Online payments are verified by the provider.');
        }

        return $this->transition($payment, $approve ? PaymentStatus::Paid : PaymentStatus::Failed, self::ACTOR_ADMIN, $adminId,
            $approve ? 'Receipt verified' : 'Receipt rejected',
            ['verified_by' => $adminId, 'verified_at' => now(), 'paid_at' => $approve ? now() : null, 'failure_reason' => $approve ? null : 'Receipt rejected']);
    }

    // ------------------------------------------------------------------ order events

    /** Order canceled: unpaid payments are canceled; money already received is queued for refund. */
    public function onOrderCanceled(Order $order, string $actorType, ?int $actorId): void
    {
        foreach (Payment::where('order_id', $order->id)->lockForUpdate()->get() as $payment) {
            if (in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::AwaitingVerification, PaymentStatus::Unpaid], true)) {
                $this->transition($payment, PaymentStatus::Canceled, $actorType, $actorId, 'Order canceled', ['collection_status' => null]);
            } elseif (($refundable = $payment->refundableMinor() - $this->openRefundsMinor($payment)) > 0) {
                app(RefundService::class)->requestForPayment($payment, $refundable, "Order #{$order->id} canceled", $actorType === self::ACTOR_ADMIN ? $actorId : null);
            }
        }
    }

    /**
     * Online payments past their expiry: confirmed with the provider when possible, otherwise
     * expired; an order left without a successful payment is canceled, releasing its stock.
     */
    public function expireStale(): int
    {
        $expired = 0;

        Payment::where('provider', Payment::PROVIDER_PAYMOB)->where('status', PaymentStatus::Pending->value)
            ->where('expires_at', '<=', now())->orderBy('id')->each(function (Payment $payment) use (&$expired) {
                try {
                    $payment = $this->refreshFromProvider($payment);
                } catch (PaymentGatewayException $e) {
                    Log::warning('Payment check before expiry failed', ['payment' => $payment->internal_reference, 'error' => $e->getMessage()]);

                    return; // try again next run rather than expiring a payment that may have succeeded
                }

                if ($payment->status !== PaymentStatus::Pending) {
                    return;
                }

                $this->transition($payment, PaymentStatus::Expired, self::ACTOR_SYSTEM, null, 'Not paid in time');
                $expired++;

                $order = Order::find($payment->order_id);
                $paid = Payment::where('order_id', $payment->order_id)->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::Pending->value])->exists();
                if ($order && !$paid && $order->canTransitionTo(OrderStatus::Canceled->value)) {
                    app(OrderWorkflow::class)->transition($order, OrderStatus::Canceled, OrderWorkflow::ACTOR_SYSTEM, null, 'Online payment not completed in time');
                }
            });

        return $expired;
    }

    // ------------------------------------------------------------------ core

    /**
     * Change a payment's status (locked, validated, recorded, projected to the order).
     *
     * @throws DomainException when the transition is not allowed
     */
    public function transition(Payment $payment, PaymentStatus $to, string $actorType, ?int $actorId, ?string $note = null, array $attributes = []): Payment
    {
        return DB::transaction(function () use ($payment, $to, $actorType, $actorId, $note, $attributes) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $from = $payment->status;

            if ($from === $to) {
                $payment->update($attributes);

                return $payment;
            }

            if (!$from->canTransitionTo($to)) {
                throw new DomainException("Payment {$payment->internal_reference} cannot go from {$from->value} to {$to->value}.");
            }

            $payment->forceFill($attributes + ['status' => $to->value]);
            if ($to === PaymentStatus::Failed) {
                $payment->failed_at ??= now();
            }
            $payment->save();

            $this->history($payment, $from, $to, $actorType, $actorId, $note);
            $this->projectOrder($payment->order_id);

            return $payment;
        });
    }

    /** orders.payment_status (+ verification fields) from the order's latest payment. */
    public function projectOrder(int $orderId): void
    {
        $payment = Payment::where('order_id', $orderId)->latest('id')->first();

        if (!$payment) {
            return;
        }

        $status = $payment->status === PaymentStatus::Pending && $payment->isCod() ? PaymentStatus::Unpaid : $payment->status;

        Order::whereKey($orderId)->update([
            'payment_status' => $status->value,
            'payment_verified_by' => $payment->verified_by,
            'payment_verified_at' => $payment->verified_at ?? $payment->paid_at,
            'updated_at' => now(),
        ]);
    }

    public function recordInternalEvent(Payment $payment, string $type, array $payload, string $result = PaymentEvent::RESULT_PROCESSED, ?string $error = null): PaymentEvent
    {
        return PaymentEvent::create([
            'payment_id' => $payment->id, 'provider' => $payment->provider, 'event_type' => $type,
            'idempotency_key' => "{$payment->provider}:internal:{$type}:".Str::uuid(),
            'result' => $result, 'error' => $error, 'payload' => $payload ?: null, 'processed_at' => now(),
        ]);
    }

    /** Is this online method usable right now (active and configured)? */
    public function providerReady(string $provider): bool
    {
        return $provider === Payment::PROVIDER_COD || $this->gateways->isReady($provider);
    }

    private function findPayment(string $provider, GatewayNotification $notification, bool $isRefund): ?Payment
    {
        $query = Payment::where('provider', $provider);

        if ($isRefund) {
            return $notification->parentTransactionId ? $query->where('provider_transaction_id', $notification->parentTransactionId)->first() : null;
        }

        if ($notification->merchantReference) {
            if ($payment = (clone $query)->where('internal_reference', $notification->merchantReference)->first()) {
                return $payment;
            }
        }

        return $notification->providerOrderId ? $query->where('provider_order_id', $notification->providerOrderId)->latest('id')->first() : null;
    }

    private function mismatch(Payment $payment, GatewayNotification $notification): ?string
    {
        $gateway = $this->gateways->for($payment->provider);

        if ($gateway instanceof PaymobGateway) {
            if ($notification->amountProviderUnits !== $gateway->toProviderUnits($payment->amountMinor())) {
                return "Amount mismatch: provider {$notification->amountProviderUnits}, expected {$gateway->toProviderUnits($payment->amountMinor())}.";
            }
            if ($notification->currency !== $gateway->currencyFor($payment)) {
                return "Currency mismatch: provider {$notification->currency}, expected {$gateway->currencyFor($payment)}.";
            }
        }

        if ($notification->merchantReference && $notification->merchantReference !== $payment->internal_reference) {
            return 'Reference mismatch.';
        }

        return null;
    }

    private function openRefundsMinor(Payment $payment): int
    {
        return Money::toMinor(Refund::where('payment_id', $payment->id)->whereIn('status', [Refund::PENDING, Refund::PROCESSING])->sum('amount'));
    }

    private function history(Payment $payment, ?PaymentStatus $from, PaymentStatus $to, string $actorType, ?int $actorId, ?string $note): void
    {
        PaymentStatusHistory::create([
            'payment_id' => $payment->id, 'from_status' => $from?->value, 'to_status' => $to->value,
            'actor_type' => $actorType, 'actor_id' => $actorId, 'note' => $note ? Str::limit($note, 250) : null,
        ]);
    }

    private function initiationResult(Payment $payment, ?string $url, ?string $error): array
    {
        return [
            'status' => $payment->status->value,
            'checkout_url' => $url,
            'reference' => $payment->internal_reference,
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'error' => $error ? api_trans('payments.initiation_failed') : null,
        ];
    }

    private function newReference(): string
    {
        return 'PAY-'.strtoupper((string) Str::ulid());
    }

    private function ttlMinutes(): int
    {
        return max(5, (int) config('services.paymob.payment_ttl_minutes', 60));
    }
}
