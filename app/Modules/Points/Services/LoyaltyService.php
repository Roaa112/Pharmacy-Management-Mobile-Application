<?php

namespace App\Modules\Points\Services;

use App\Models\LoyaltyLedgerEntry;
use App\Models\LoyaltyPointReversal;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one definition of loyalty points, used by the customer API, the dashboard, redemption
 * and refunds.
 *
 * Points are earned per order (orders.points_earned, never changed), reduced by refund reversals
 * (loyalty_point_reversals), valid for points_validity_days from the order date, and redeemed
 * per order by an admin. "Net" points of an order = earned − reversed. Every movement is also
 * written to the append-only loyalty_ledger.
 *
 * Order timestamps are stored in UTC by Laravel, so validity is measured with now() (UTC);
 * StoreClock is only for wall-clock times typed in by the admin.
 */
class LoyaltyService
{
    public function pointsFor(int $subtotalMinor, ?Setting $settings = null): int
    {
        $settings ??= Setting::current();
        $spendMinor = $settings->pointsSpendMinor();

        return $spendMinor > 0 ? intdiv($subtotalMinor, $spendMinor) * $settings->pointsPerSpend() : 0;
    }

    public function validityDays(): int
    {
        return Setting::current()->pointsValidityDays();
    }

    public function validFrom(): Carbon
    {
        return now()->subDays($this->validityDays());
    }

    public function expiresAt(Order $order): Carbon
    {
        return $order->created_at->copy()->addDays($this->validityDays());
    }

    /** Orders with points: points_reversed and points_net selected alongside. */
    public function withNetPoints(Builder $query): Builder
    {
        return $query->select('orders.*')
            ->selectSub(LoyaltyPointReversal::selectRaw('coalesce(sum(points), 0)')->whereColumn('order_id', 'orders.id'), 'points_reversed')
            ->where('orders.points_earned', '>', 0)
            ->where('orders.status', '!=', 'canceled');
    }

    /** Points that can still be used: earned, not redeemed, not expired, something left after refunds. */
    public function valid(?int $userId = null): Builder
    {
        return $this->withNetPoints(Order::query())
            ->when($userId, fn ($q) => $q->where('orders.user_id', $userId))
            ->where('orders.points_status', 'earned')
            ->where('orders.created_at', '>=', $this->validFrom())
            ->whereRaw('orders.points_earned > (select coalesce(sum(points), 0) from loyalty_point_reversals where loyalty_point_reversals.order_id = orders.id)');
    }

    public function expired(int $userId): Builder
    {
        return $this->withNetPoints(Order::query())
            ->where('orders.user_id', $userId)
            ->where('orders.points_status', 'earned')
            ->where('orders.created_at', '<', $this->validFrom())
            ->whereRaw('orders.points_earned > (select coalesce(sum(points), 0) from loyalty_point_reversals where loyalty_point_reversals.order_id = orders.id)');
    }

    public function redeemed(int $userId): Builder
    {
        return $this->withNetPoints(Order::query())
            ->where('orders.user_id', $userId)
            ->where('orders.points_status', 'redeemed');
    }

    /** Sum of net points of the orders in $query, computed in SQL. */
    public function netTotal(Builder $query): int
    {
        return (int) $query->sum(DB::raw('orders.points_earned - (select coalesce(sum(points), 0) from loyalty_point_reversals where loyalty_point_reversals.order_id = orders.id)'));
    }

    public static function net(Order $order): int
    {
        return max(0, (int) $order->points_earned - (int) ($order->points_reversed ?? $order->pointReversals()->sum('points')));
    }

    /** @return int how many orders were redeemed */
    public function redeem(array $orderIds, ?int $adminId): int
    {
        return DB::transaction(function () use ($orderIds, $adminId) {
            $orders = $this->valid()->whereIn('orders.id', $orderIds)->lockForUpdate()->get();

            foreach ($orders as $order) {
                Order::whereKey($order->id)->where('points_status', 'earned')->update(['points_status' => 'redeemed']);

                LoyaltyLedgerEntry::firstOrCreate(
                    ['type' => LoyaltyLedgerEntry::REDEEM, 'order_id' => $order->id, 'reference_type' => 'order', 'reference_id' => $order->id],
                    ['user_id' => $order->user_id, 'points' => -self::net($order), 'admin_id' => $adminId, 'occurred_at' => now()],
                );
            }

            return $orders->count();
        });
    }

    public function recordEarn(Order $order): void
    {
        if ((int) $order->points_earned <= 0) {
            return;
        }

        LoyaltyLedgerEntry::firstOrCreate(
            ['type' => LoyaltyLedgerEntry::EARN, 'order_id' => $order->id, 'reference_type' => 'order', 'reference_id' => $order->id],
            ['user_id' => $order->user_id, 'points' => (int) $order->points_earned, 'occurred_at' => now()],
        );
    }

    public function recordReversal(LoyaltyPointReversal $reversal): void
    {
        if ((int) $reversal->points <= 0) {
            return;
        }

        LoyaltyLedgerEntry::firstOrCreate(
            ['type' => LoyaltyLedgerEntry::REVERSE, 'order_id' => $reversal->order_id, 'reference_type' => 'loyalty_point_reversal', 'reference_id' => $reversal->id],
            ['user_id' => $reversal->user_id, 'points' => -(int) $reversal->points, 'admin_id' => $reversal->admin_id, 'occurred_at' => now()],
        );
    }

    /** Write an "expire" entry for every order whose unused points passed their validity. */
    public function recordExpirations(): int
    {
        $count = 0;

        $this->withNetPoints(Order::query())
            ->where('orders.points_status', 'earned')
            ->where('orders.created_at', '<', $this->validFrom())
            ->whereNotExists(fn ($q) => $q->from('loyalty_ledger')->whereColumn('loyalty_ledger.order_id', 'orders.id')->where('loyalty_ledger.type', LoyaltyLedgerEntry::EXPIRE))
            ->chunkById(500, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    if (($net = self::net($order)) > 0) {
                        LoyaltyLedgerEntry::firstOrCreate(
                            ['type' => LoyaltyLedgerEntry::EXPIRE, 'order_id' => $order->id, 'reference_type' => 'order', 'reference_id' => $order->id],
                            ['user_id' => $order->user_id, 'points' => -$net, 'occurred_at' => $this->expiresAt($order)],
                        );
                        $count++;
                    }
                }
            }, 'orders.id', 'id');

        return $count;
    }

    /** Points of one customer's profile: available, owed (reversed after redemption), lifetime. */
    public function summary(int $userId): array
    {
        $valid = $this->valid($userId)->get();

        return [
            'available' => $valid->sum(fn ($o) => self::net($o)),
            'owed' => (int) LoyaltyPointReversal::where('user_id', $userId)->where('points_status_at_reversal', 'redeemed')->sum('points'),
        ];
    }
}
