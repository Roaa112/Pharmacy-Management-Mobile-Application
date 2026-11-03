<?php

namespace App\Modules\Points\Services;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\LoyaltyLedgerEntry as Entry;
use App\Models\LoyaltyPointReversal;
use App\Models\LoyaltyRedemption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ReturnRequest;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Promotions\Engine\PricingResult;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The loyalty programme's business rules, on top of the points ledger (LoyaltyLedger).
 *
 * Life of an order's points (docs/loyalty.md):
 *  - checkout: the order earns points (pointsForCheckout, by the settings) and gets a lot, pending
 *    until the moment the settings make points usable (immediately, once paid, once completed);
 *  - activation: the lot becomes usable and its validity starts;
 *  - cancellation: the points the order paid with come back to the customer, and its earned points
 *    are reversed;
 *  - refund (accepted return): earned points are taken back in proportion to the money refunded
 *    (decision D2, LoyaltyPointReversal) and the points the order paid with come back in the
 *    same proportion;
 *  - taking points back never makes a balance negative: they come out of the order's own lot,
 *    then out of the customer's other usable points (oldest first), and whatever is still missing
 *    is recorded as owed (metadata.shortfall) for staff to see.
 * Every step is keyed by its event, so repeating it (double click, retry, two admins) does nothing.
 */
class LoyaltyService
{
    public function __construct(private LoyaltyLedger $ledger)
    {
    }

    public function settings(): LoyaltySettings
    {
        return LoyaltySettings::current();
    }

    public function ledger(): LoyaltyLedger
    {
        return $this->ledger;
    }

    // ---------------------------------------------------------------- earning

    /** The basic earning rule: points for an amount (minor units). */
    public function pointsFor(int $amountMinor, ?Setting $settings = null): int
    {
        $settings = $settings ? new LoyaltySettings($settings) : $this->settings();
        $spendMinor = $settings->spendMinor();

        return $spendMinor > 0 && $amountMinor > 0 ? intdiv($amountMinor, $spendMinor) * $settings->pointsPerSpend() : 0;
    }

    /** Points an order priced as $result earns, by the loyalty settings. */
    public function pointsForCheckout(PricingResult $result, int $pointsDiscountMinor, bool $isCod, ?LoyaltySettings $settings = null): int
    {
        $settings ??= $this->settings();

        if (!$settings->enabled() || ($isCod && !$settings->earnOnCod())) {
            return 0;
        }

        $deliveryCharged = max(0, $result->deliveryFee - $result->deliveryDiscount);
        $payable = max(0, $result->total() - $pointsDiscountMinor);

        if ($payable < $settings->minOrderMinor()) {
            return 0;
        }

        if ($settings->basis() === LoyaltySettings::BASIS_PAID) {
            $base = $settings->earnOnShipping() ? $payable : max(0, $payable - $deliveryCharged);
        } else {
            $base = $result->subtotal;
            if ($settings->discountsReducePoints()) {
                $base -= ($result->orderDiscount() - $result->deliveryDiscount) + $pointsDiscountMinor;
            }
            if ($settings->earnOnShipping()) {
                $base += $deliveryCharged;
            }
        }

        $points = $this->pointsFor(max(0, $base), $settings->setting());
        $max = $settings->maxPointsPerOrder();

        return $max !== null ? min($points, $max) : $points;
    }

    /** Days points stay valid (0 = they lapse the day they become usable). Never-expiring: 0 here. */
    public function validityDays(): int
    {
        return (int) ($this->settings()->validityDays() ?? 0);
    }

    /** The order's lot (its earned points), if it earned any. */
    public function orderLot(int $orderId, bool $lock = false): ?Entry
    {
        return Entry::lots()->where('order_id', $orderId)->where('type', Entry::EARNED)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    /** Checkout: the order's points become a lot (usable now, or pending until paid / completed). */
    public function recordEarn(Order $order): ?Entry
    {
        $points = (int) $order->points_earned;
        if ($points <= 0) {
            return null;
        }

        $settings = $this->settings();
        $immediate = $settings->activation() === LoyaltySettings::ACTIVATE_IMMEDIATELY;

        return $this->ledger->credit($order->user_id, Entry::EARNED, $points, "earned:order:{$order->id}", [
            'order_id' => $order->id,
            'reference_type' => 'order',
            'reference_id' => $order->id,
            'description' => "Order #{$order->id}",
            'status' => $immediate ? Entry::STATUS_ACTIVE : Entry::STATUS_PENDING,
            'earned_at' => $immediate ? now() : null,
            'expires_at' => $immediate ? LoyaltyLedger::expiryFrom(now(), $settings->validityDays()) : null,
            'metadata' => ['basis' => $settings->basis(), 'activation' => $settings->activation()],
        ]);
    }

    /** The order's payment was confirmed (PaymentService::projectOrder). */
    public function onOrderPaid(int $orderId): void
    {
        if ($this->settings()->activation() === LoyaltySettings::ACTIVATE_ON_PAYMENT) {
            $this->activateOrderLot($orderId);
        }
    }

    /** The order was completed (OrderWorkflow). */
    public function onOrderCompleted(Order $order): void
    {
        $activation = $this->settings()->activation();

        if ($activation === LoyaltySettings::ACTIVATE_ON_COMPLETION
            || ($activation === LoyaltySettings::ACTIVATE_ON_PAYMENT && $order->fresh()->paymentStatusEnum()->isSettledPayment())) {
            $this->activateOrderLot($order->id);
        }
    }

    /** Safety net for the scheduler: pending lots whose order now qualifies. */
    public function activateDueLots(): int
    {
        $activation = $this->settings()->activation();
        $count = 0;

        Entry::lots()->where('status', Entry::STATUS_PENDING)->where('type', Entry::EARNED)
            ->whereHas('order', function ($q) use ($activation) {
                $q->where('status', '!=', 'canceled')->when($activation === LoyaltySettings::ACTIVATE_ON_COMPLETION, fn ($o) => $o->where('status', 'completed'))
                    ->when($activation === LoyaltySettings::ACTIVATE_ON_PAYMENT, fn ($o) => $o->whereIn('payment_status', ['paid', 'partially_refunded', 'refunded']));
            })
            ->chunkById(200, function ($lots) use (&$count) {
                foreach ($lots as $lot) {
                    $count += $this->ledger->activate($lot, $this->settings()->validityDays())->status !== Entry::STATUS_PENDING ? 1 : 0;
                }
            });

        return $count;
    }

    private function activateOrderLot(int $orderId): void
    {
        if ($lot = $this->orderLot($orderId)) {
            $this->ledger->activate($lot, $this->settings()->validityDays());
        }
    }

    // ---------------------------------------------------------------- cancellation and refunds

    /** Inside the order cancellation (OrderWorkflow, order row locked). */
    public function onOrderCanceled(Order $order, ?int $adminId): void
    {
        DB::transaction(function () use ($order, $adminId) {
            $this->ledger->lockCustomer($order->user_id);

            // Points the order paid with come back.
            $redemption = LoyaltyRedemption::where('order_id', $order->id)->where('source', LoyaltyRedemption::SOURCE_CHECKOUT)
                ->where('status', LoyaltyRedemption::FULFILLED)->lockForUpdate()->first();
            if ($redemption?->ledgerEntry) {
                $this->ledger->restore($redemption->ledgerEntry, "cancelled:checkout:{$order->id}", null, [
                    'admin_id' => $adminId, 'description' => "Order #{$order->id} canceled: points used on it returned",
                ]);
                $redemption->forceFill([
                    'status' => LoyaltyRedemption::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $adminId,
                    'cancellation_reason' => 'Order canceled',
                ])->save();
            }

            // Points the order earned are void (what refunds already took back is not taken twice).
            $toReverse = (int) $order->points_earned - (int) LoyaltyPointReversal::where('order_id', $order->id)->sum('points');
            if ($toReverse > 0 && $this->orderLot($order->id)) {
                $this->takeBack($order, Entry::REVERSED, $toReverse, "reversed:order:{$order->id}", [
                    'admin_id' => $adminId, 'reference_type' => 'order_cancellation', 'reference_id' => $order->id,
                    'description' => "Order #{$order->id} canceled",
                ]);
            }
        });
    }

    /** The ledger side of a refund reversal (ReturnDecisionService). */
    public function recordReversal(LoyaltyPointReversal $reversal): ?Entry
    {
        if ((int) $reversal->points <= 0) {
            return null;
        }

        return $this->takeBack($reversal->order, Entry::REFUNDED, (int) $reversal->points, "refunded:loyalty_point_reversal:{$reversal->id}", [
            'admin_id' => $reversal->admin_id,
            'reference_type' => 'loyalty_point_reversal',
            'reference_id' => $reversal->id,
            'description' => "Refund of order #{$reversal->order_id} (return #{$reversal->return_request_id})",
        ]);
    }

    /**
     * A refund also gives back the points the order paid with, in proportion to the money refunded
     * (cumulative, rounded half up once, like D2). The whole amount once everything paid is refunded.
     */
    public function restoreForRefund(Order $order, ReturnRequest $return, int $refundedTotalMinor, ?int $adminId): ?Entry
    {
        $used = (int) $order->points_redeemed;
        $paidMinor = Money::toMinor($order->total_price);

        if ($used <= 0 || $paidMinor <= 0 || $refundedTotalMinor <= 0) {
            return null;
        }

        $redemption = LoyaltyRedemption::where('order_id', $order->id)->where('source', LoyaltyRedemption::SOURCE_CHECKOUT)->first();
        if (!$redemption?->ledgerEntry) {
            return null;
        }

        $target = $refundedTotalMinor >= $paidMinor ? $used : min($used, intdiv($used * $refundedTotalMinor * 2 + $paidMinor, $paidMinor * 2));
        $already = (int) Entry::where('reference_type', 'loyalty_ledger')->where('reference_id', $redemption->ledger_entry_id)->sum('points');

        if ($target - $already <= 0) {
            return null;
        }

        return $this->ledger->restore($redemption->ledgerEntry, "cancelled:return:{$return->id}", $target - $already, [
            'admin_id' => $adminId, 'description' => "Refund of order #{$order->id}: points used on it returned",
        ]);
    }

    /** What the order's points were when a refund reversed them (loyalty_point_reversals). */
    public function reversalStatus(Order $order): string
    {
        $lot = $this->orderLot($order->id);

        if (!$lot) {
            return 'earned';
        }
        if ($lot->lotAllocations()->whereHas('entry', fn ($q) => $q->where('type', Entry::REDEEMED))->exists()) {
            return 'redeemed';
        }

        return $lot->status === Entry::STATUS_EXPIRED || ($lot->status === Entry::STATUS_ACTIVE && $lot->isExpiredNow()) ? 'expired' : 'earned';
    }

    /** Order's own lot first, then the customer's other usable points; the rest is owed. */
    private function takeBack(Order $order, string $type, int $points, string $key, array $attributes): Entry
    {
        return DB::transaction(function () use ($order, $type, $points, $key, $attributes) {
            $this->ledger->lockCustomer($order->user_id);
            $own = $this->orderLot($order->id, lock: true);
            $lots = collect($own && $own->remaining_points > 0 ? [$own] : [])
                ->merge($this->ledger->usableLots($order->user_id)->reject(fn ($lot) => $lot->id === $own?->id));

            return $this->ledger->debit($order->user_id, $type, $points, $key, $attributes + ['order_id' => $order->id], $lots, allowShortfall: true);
        });
    }

    // ---------------------------------------------------------------- redemption

    /**
     * Dashboard "Redeem" of an order's points (the customer used them in the pharmacy): what is left
     * of each order's own lot, once each. Only usable lots (activated, not expired, not canceled).
     *
     * @return int how many orders were redeemed
     */
    public function redeem(array $orderIds, ?int $adminId): int
    {
        return DB::transaction(function () use ($orderIds, $adminId) {
            $count = 0;

            foreach (Order::whereIn('id', $orderIds)->where('status', '!=', 'canceled')->orderBy('id')->get() as $order) {
                $this->ledger->lockCustomer($order->user_id);
                $lot = Entry::usable()->where('order_id', $order->id)->where('type', Entry::EARNED)->lockForUpdate()->first();

                if (!$lot || $this->ledger->find("redeemed:order:{$order->id}")) {
                    continue;
                }

                $this->ledger->debit($order->user_id, Entry::REDEEMED, $lot->remaining_points, "redeemed:order:{$order->id}", [
                    'order_id' => $order->id, 'reference_type' => 'order', 'reference_id' => $order->id, 'admin_id' => $adminId,
                    'description' => "Points of order #{$order->id} redeemed at the pharmacy",
                ], collect([$lot]));
                Order::whereKey($order->id)->update(['points_status' => 'redeemed']);
                $count++;
            }

            return $count;
        });
    }

    /**
     * Checkout: may these points pay for this cart? Called under the checkout locks (customer row).
     *
     * @return array{points: int, discount: int} discount in minor units
     *
     * @throws DomainException with a message for the customer
     */
    public function quoteCheckout(User $user, int $requested, PricingResult $result, PaymentMethod $method): array
    {
        $settings = $this->settings();

        if (!$settings->checkoutRedemptionEnabled()) {
            throw new DomainException(api_trans('points.checkout_disabled'));
        }
        if ($requested < max(1, $settings->minRedeemPoints())) {
            throw new DomainException(api_trans('points.below_minimum', ['min' => max(1, $settings->minRedeemPoints())]));
        }
        if (!$settings->combineWithDiscounts() && ($result->coupon || $result->orderDiscount() > 0 || $result->itemsDiscount() > 0)) {
            throw new DomainException(api_trans('points.no_combination'));
        }
        if (!$settings->allowMultipleRedemptions() && $this->hasOpenRedemption($user->id)) {
            throw new DomainException(api_trans('points.one_at_a_time'));
        }

        $balance = $this->ledger->balance($user->id);
        if ($requested > $balance) {
            throw new InsufficientPoints($requested, $balance);
        }

        // Points pay for products, never for delivery.
        $payableProducts = max(0, $result->total() - max(0, $result->deliveryFee - $result->deliveryDiscount));
        $cap = min($balance, $settings->pointsWorth($payableProducts), $settings->maxRedeemPointsPerOrder() ?? PHP_INT_MAX);

        if ($requested > $cap) {
            throw new DomainException(api_trans('points.max_for_order', ['max' => $cap]));
        }
        if (!$settings->allowPartialRedemption() && $requested < $cap) {
            throw new DomainException(api_trans('points.must_redeem_all', ['max' => $cap]));
        }

        $discount = $settings->valueMinor($requested);
        if ($discount <= 0) {
            throw new DomainException(api_trans('points.worth_nothing'));
        }
        if ($method->provider === Payment::PROVIDER_PAYMOB && $result->total() - $discount <= 0) {
            throw new DomainException(api_trans('points.online_needs_amount'));
        }

        return ['points' => $requested, 'discount' => $discount];
    }

    /** Checkout: the points leave the customer's oldest lots and pay part of the order. */
    public function applyCheckout(Order $order, array $quote): LoyaltyRedemption
    {
        return DB::transaction(function () use ($order, $quote) {
            $entry = $this->ledger->debit($order->user_id, Entry::REDEEMED, $quote['points'], "redeemed:checkout:{$order->id}", [
                'order_id' => $order->id, 'reference_type' => 'order', 'reference_id' => $order->id,
                'description' => "Paid part of order #{$order->id}",
                'metadata' => ['value' => Money::fromMinor($quote['discount'])],
            ]);

            $redemption = LoyaltyRedemption::create([
                'number' => 'TMP-'.\Illuminate\Support\Str::random(20),
                'user_id' => $order->user_id,
                'order_id' => $order->id,
                'source' => LoyaltyRedemption::SOURCE_CHECKOUT,
                'quantity' => 1,
                'points' => $quote['points'],
                'value' => Money::fromMinor($quote['discount']),
                'status' => LoyaltyRedemption::FULFILLED,
                'ledger_entry_id' => $entry->id,
            ]);
            $redemption->forceFill(['number' => sprintf('RDM-%06d', $redemption->id), 'fulfilled_at' => now()])->save();

            return $redemption;
        });
    }

    /** An open reward redemption, or points paid on an order still in progress. */
    public function hasOpenRedemption(int $userId): bool
    {
        return LoyaltyRedemption::where('user_id', $userId)->where(function ($q) {
            $q->whereIn('status', LoyaltyRedemption::OPEN)
                ->orWhere(fn ($c) => $c->where('source', LoyaltyRedemption::SOURCE_CHECKOUT)->where('status', LoyaltyRedemption::FULFILLED)
                    ->whereHas('order', fn ($o) => $o->whereNotIn('status', ['completed', 'canceled'])));
        })->exists();
    }

    // ---------------------------------------------------------------- manual changes

    /**
     * Points added or removed by hand. Positive: a new lot ("adjusted", or "bonus") valid for the
     * usual period unless $validDays says otherwise. Negative: taken from the oldest usable points;
     * more than the customer has is refused (a balance is never made negative). $admin null = the
     * system (seeders, scripts); every dashboard adjustment passes the admin.
     */
    public function adjust(User $customer, int $points, string $reason, ?Admin $admin, string $type = Entry::ADJUSTED, ?int $validDays = null, ?string $requestKey = null): Entry
    {
        if ($points === 0) {
            throw new DomainException('Enter a number of points other than zero.');
        }
        if (trim($reason) === '') {
            throw new DomainException('A reason is required.');
        }
        if ($type === Entry::BONUS && $points < 0) {
            throw new DomainException('A bonus adds points; use an adjustment to remove them.');
        }

        $key = 'adjusted:'.($requestKey ?: \Illuminate\Support\Str::uuid());

        return DB::transaction(function () use ($customer, $points, $reason, $admin, $type, $validDays, $key) {
            $before = $this->ledger->balance($customer->id);
            $attributes = ['admin_id' => $admin?->id, 'description' => $reason, 'reference_type' => $admin ? 'admin' : 'system', 'reference_id' => $admin?->id];

            if ($points > 0) {
                $days = $validDays ?? $this->settings()->validityDays();
                $entry = $this->ledger->credit($customer->id, $type, $points, $key, $attributes + [
                    'earned_at' => now(), 'expires_at' => LoyaltyLedger::expiryFrom(now(), $days),
                    'metadata' => ['reason' => $reason],
                ]);
            } else {
                $entry = $this->ledger->debit($customer->id, Entry::ADJUSTED, -$points, $key, $attributes + ['metadata' => ['reason' => $reason]]);
            }

            AdminAuditLog::record('loyalty.adjust', $customer, [
                'type' => $type, 'points' => $points, 'reason' => $reason, 'entry_id' => $entry->id,
                'before' => ['balance' => $before], 'after' => ['balance' => $this->ledger->balance($customer->id)],
            ]);

            return $entry;
        });
    }

    // ---------------------------------------------------------------- expiry

    /**
     * Write off every lot past its expiry (indexed on status + expires_at: only due lots are read).
     *
     * @return int lots expired
     */
    public function recordExpirations(): int
    {
        $count = 0;

        Entry::lots()->where('status', Entry::STATUS_ACTIVE)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->chunkById(500, function ($lots) use (&$count) {
                foreach ($lots as $lot) {
                    $count += $this->ledger->expire($lot) ? 1 : 0;
                }
            });

        return $count;
    }

    // ---------------------------------------------------------------- reads

    /**
     * A customer's points: usable now, pending, expiring soon, and lifetime totals by kind.
     *
     * @return array<string, int|string|null>
     */
    public function summary(int $userId): array
    {
        $settings = $this->settings();
        $lots = Entry::lots()->where('user_id', $userId);
        $byType = Entry::where('user_id', $userId)->groupBy('type')->selectRaw('type, sum(points) as total')->pluck('total', 'type');
        $soon = now()->addDays($settings->expiryWarningDays());
        $nextExpiry = (clone $lots)->usable()->whereNotNull('expires_at')->min('expires_at');

        $owed = (int) Entry::where('user_id', $userId)->whereIn('type', [Entry::REFUNDED, Entry::REVERSED])->whereNotNull('metadata')->get(['metadata'])
            ->sum(fn ($e) => (int) ($e->metadata['shortfall'] ?? 0));

        return [
            'available' => $this->ledger->balance($userId),
            'pending' => (int) (clone $lots)->where('status', Entry::STATUS_PENDING)->sum('remaining_points'),
            'expiring_soon' => (int) (clone $lots)->usable()->whereNotNull('expires_at')->where('expires_at', '<=', $soon)->sum('remaining_points'),
            'next_expiry_at' => $nextExpiry ? Carbon::parse($nextExpiry)->toIso8601String() : null,
            'total_earned' => (int) (($byType[Entry::EARNED] ?? 0) + ($byType[Entry::BONUS] ?? 0)
                + Entry::where('user_id', $userId)->where('type', Entry::ADJUSTED)->where('points', '>', 0)->sum('points')),
            'total_redeemed' => -(int) ($byType[Entry::REDEEMED] ?? 0),
            'total_returned' => (int) ($byType[Entry::CANCELLED] ?? 0),
            'total_expired' => -(int) ($byType[Entry::EXPIRED] ?? 0),
            'total_reversed' => -(int) (($byType[Entry::REVERSED] ?? 0) + ($byType[Entry::REFUNDED] ?? 0)),
            'total_adjusted_down' => -(int) Entry::where('user_id', $userId)->where('type', Entry::ADJUSTED)->where('points', '<', 0)->sum('points'),
            'owed' => $owed,
        ];
    }

    /** Usable lots expiring within $days (dashboard and customer "expiring soon"). */
    public function expiringLots(?int $userId, int $days): \Illuminate\Database\Eloquent\Builder
    {
        return Entry::usable()->whereNotNull('expires_at')->where('expires_at', '<=', now()->addDays($days))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('expires_at');
    }

    /**
     * Per-lot amounts: what refunds/cancellation took back, what was redeemed and what expired.
     *
     * @param  list<int>  $lotIds
     * @return Collection<int, array{reversed: int, redeemed: int, expired: int}>
     */
    public function lotBreakdown(array $lotIds): Collection
    {
        $rows = DB::table('loyalty_ledger_allocations as a')->join('loyalty_ledger as e', 'e.id', '=', 'a.entry_id')
            ->whereIn('a.lot_id', $lotIds ?: [0])
            ->groupBy('a.lot_id', 'e.type')->selectRaw('a.lot_id, e.type, sum(a.points) as points')->get();

        return collect($lotIds)->mapWithKeys(fn ($id) => [$id => [
            'reversed' => (int) $rows->where('lot_id', $id)->whereIn('type', [Entry::REVERSED, Entry::REFUNDED])->sum('points'),
            'redeemed' => (int) $rows->where('lot_id', $id)->whereIn('type', [Entry::REDEEMED, Entry::CANCELLED])->sum('points'),
            'expired' => (int) $rows->where('lot_id', $id)->where('type', Entry::EXPIRED)->sum('points'),
        ]]);
    }
}
