<?php

namespace App\Modules\Points\Services;

use App\Models\LoyaltyAllocation;
use App\Models\LoyaltyLedgerEntry as Entry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The points ledger: the only code that writes loyalty_ledger.
 *
 * The balance is never stored; it is the sum of the lots' remaining points, and every change to a
 * lot is an entry with allocations saying which lots it touched, so at all times
 * Σ entry points = Σ lot remaining points (for each customer).
 *
 * Concurrency: every method takes the customer's row lock first (users, the same lock checkout
 * takes), so two redemptions, an expiry run and a refund for one customer happen one after the
 * other and a lot can never be spent twice. Methods must run inside a transaction (they open one).
 *
 * Idempotency: every entry has an idempotency key naming the event ("earned:order:12",
 * "redeemed:redemption:5"…). Recording the same event again returns the entry written the first
 * time and changes nothing.
 */
class LoyaltyLedger
{
    public function lockCustomer(int $userId): void
    {
        User::whereKey($userId)->lockForUpdate()->first();
    }

    public function find(string $key): ?Entry
    {
        return Entry::where('idempotency_key', $key)->first();
    }

    /**
     * New points (a lot). $attributes: order_id, reference_type, reference_id, admin_id,
     * description, metadata, status (pending|active), earned_at, expires_at.
     */
    public function credit(int $userId, string $type, int $points, string $key, array $attributes = []): Entry
    {
        return DB::transaction(function () use ($userId, $type, $points, $key, $attributes) {
            $this->lockCustomer($userId);

            if ($existing = $this->find($key)) {
                return $existing;
            }

            $status = $attributes['status'] ?? Entry::STATUS_ACTIVE;

            return Entry::create([
                'user_id' => $userId,
                'type' => $type,
                'points' => $points,
                'remaining_points' => $points,
                'status' => $status,
                'occurred_at' => now(),
                'idempotency_key' => $key,
            ] + array_intersect_key($attributes, array_flip(['order_id', 'reference_type', 'reference_id', 'admin_id', 'description', 'metadata', 'earned_at', 'expires_at'])));
        });
    }

    /** A pending lot becomes usable now; its validity starts now. */
    public function activate(Entry $lot, ?int $validityDays): Entry
    {
        return DB::transaction(function () use ($lot, $validityDays) {
            $this->lockCustomer($lot->user_id);
            $lot = Entry::lockForUpdate()->findOrFail($lot->id);

            if ($lot->status !== Entry::STATUS_PENDING) {
                return $lot;
            }

            $lot->forceFill([
                'status' => $lot->remaining_points > 0 ? Entry::STATUS_ACTIVE : Entry::STATUS_DEPLETED,
                'earned_at' => now(),
                'expires_at' => $validityDays === null ? null : now()->addDays($validityDays),
            ])->save();

            return $lot;
        });
    }

    /**
     * Take $points from the customer. Lots are used in the order given ($lots), by default the
     * usable lots soonest to expire first (FIFO: oldest valid points first).
     *
     * $allowShortfall false: not enough points → InsufficientPoints, nothing written.
     * $allowShortfall true (taking points back after a refund or cancellation): take what there is
     * and record the rest as metadata.shortfall; balances never go below zero.
     *
     * @param  Collection<int, Entry>|null  $lots  locked lots, in the order to use them
     */
    public function debit(int $userId, string $type, int $points, string $key, array $attributes = [], ?Collection $lots = null, bool $allowShortfall = false): Entry
    {
        return DB::transaction(function () use ($userId, $type, $points, $key, $attributes, $lots, $allowShortfall) {
            $this->lockCustomer($userId);

            if ($existing = $this->find($key)) {
                return $existing;
            }

            $lots ??= $this->usableLots($userId);
            $available = (int) $lots->sum('remaining_points');

            if ($available < $points && !$allowShortfall) {
                throw new InsufficientPoints($points, $available);
            }

            $plan = [];
            $left = $points;
            foreach ($lots as $lot) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, (int) $lot->remaining_points);
                if ($take > 0) {
                    $plan[] = [$lot, $take];
                    $left -= $take;
                }
            }

            $metadata = $attributes['metadata'] ?? [];
            if ($left > 0) {
                $metadata['shortfall'] = $left;
            }

            $entry = Entry::create([
                'user_id' => $userId,
                'type' => $type,
                'points' => -($points - $left),
                'status' => Entry::STATUS_POSTED,
                'occurred_at' => now(),
                'idempotency_key' => $key,
                'metadata' => $metadata ?: null,
            ] + array_intersect_key($attributes, array_flip(['order_id', 'reference_type', 'reference_id', 'admin_id', 'description'])));

            foreach ($plan as [$lot, $take]) {
                $this->takeFromLot($entry, $lot, $take);
            }

            return $entry;
        });
    }

    /**
     * Give back (up to $points of) what a debit took, to the same lots, newest allocation first.
     * The lots keep their original expiry: points given back after their lot expired lapse at
     * the next expiry run. Returns the "cancelled" entry, or null when nothing was left to give.
     */
    public function restore(Entry $debit, string $key, ?int $points = null, array $attributes = []): ?Entry
    {
        return DB::transaction(function () use ($debit, $key, $points, $attributes) {
            $this->lockCustomer($debit->user_id);

            if ($existing = $this->find($key)) {
                return $existing;
            }

            $restoredPerLot = LoyaltyAllocation::query()
                ->whereIn('entry_id', Entry::where('reference_type', 'loyalty_ledger')->where('reference_id', $debit->id)->select('id'))
                ->groupBy('lot_id')->selectRaw('lot_id, -sum(points) as restored')->pluck('restored', 'lot_id');

            $plan = [];
            $left = $points ?? PHP_INT_MAX;
            foreach ($debit->allocations()->orderByDesc('id')->get() as $allocation) {
                $give = min($left, $allocation->points - (int) ($restoredPerLot[$allocation->lot_id] ?? 0));
                if ($give > 0) {
                    $plan[] = [$allocation->lot_id, $give];
                    $left -= $give;
                    $restoredPerLot[$allocation->lot_id] = (int) ($restoredPerLot[$allocation->lot_id] ?? 0) + $give;
                }
            }

            $total = array_sum(array_column($plan, 1));
            if ($total === 0) {
                return null;
            }

            $entry = Entry::create([
                'user_id' => $debit->user_id,
                'order_id' => $attributes['order_id'] ?? $debit->order_id,
                'type' => Entry::CANCELLED,
                'points' => $total,
                'status' => Entry::STATUS_POSTED,
                'reference_type' => 'loyalty_ledger',
                'reference_id' => $debit->id,
                'occurred_at' => now(),
                'idempotency_key' => $key,
            ] + array_intersect_key($attributes, array_flip(['admin_id', 'description', 'metadata'])));

            foreach ($plan as [$lotId, $give]) {
                $lot = Entry::lockForUpdate()->findOrFail($lotId);
                $lot->forceFill([
                    'remaining_points' => $lot->remaining_points + $give,
                    // A pending or void lot stays as it was; a used one is usable again.
                    'status' => in_array($lot->status, [Entry::STATUS_DEPLETED, Entry::STATUS_EXPIRED], true) ? Entry::STATUS_ACTIVE : $lot->status,
                ])->save();
                LoyaltyAllocation::create(['entry_id' => $entry->id, 'lot_id' => $lot->id, 'points' => -$give]);
            }

            return $entry;
        });
    }

    /** Write off what is left of a lot whose expiry has passed (once; "expired:lot:{id}"). */
    public function expire(Entry $lot): ?Entry
    {
        return DB::transaction(function () use ($lot) {
            $this->lockCustomer($lot->user_id);
            $lot = Entry::lockForUpdate()->findOrFail($lot->id);

            if ($lot->status !== Entry::STATUS_ACTIVE || !$lot->isExpiredNow()) {
                return null;
            }

            if ($lot->remaining_points <= 0) {
                $lot->forceFill(['status' => Entry::STATUS_DEPLETED])->save();

                return null;
            }

            // Points given back after an earlier expiry get a key of their own.
            $key = 'expired:lot:'.$lot->id.(Entry::where('idempotency_key', 'expired:lot:'.$lot->id)->exists() ? ':'.now()->timestamp.':'.$lot->remaining_points : '');
            $entry = Entry::create([
                'user_id' => $lot->user_id,
                'order_id' => $lot->order_id,
                'type' => Entry::EXPIRED,
                'points' => -$lot->remaining_points,
                'status' => Entry::STATUS_POSTED,
                'reference_type' => 'loyalty_ledger',
                'reference_id' => $lot->id,
                'description' => 'Points expired on '.$lot->expires_at->toDateString(),
                'occurred_at' => $lot->expires_at,
                'idempotency_key' => $key,
            ]);
            $this->takeFromLot($entry, $lot, $lot->remaining_points, Entry::STATUS_EXPIRED);

            return $entry;
        });
    }

    /** @return Collection<int, Entry> usable lots, locked, soonest expiry first (never-expiring last) */
    public function usableLots(int $userId): Collection
    {
        return Entry::usable()->where('user_id', $userId)
            ->orderByRaw('case when expires_at is null then 1 else 0 end')
            ->orderBy('expires_at')->orderBy('id')
            ->lockForUpdate()->get();
    }

    public function balance(int $userId): int
    {
        return (int) Entry::usable()->where('user_id', $userId)->sum('remaining_points');
    }

    private function takeFromLot(Entry $entry, Entry $lot, int $points, ?string $statusWhenEmpty = null): void
    {
        $remaining = $lot->remaining_points - $points;
        $changes = ['remaining_points' => $remaining];

        if ($remaining === 0) {
            $changes['status'] = $statusWhenEmpty ?? match (true) {
                $lot->status === Entry::STATUS_PENDING && in_array($entry->type, [Entry::REVERSED, Entry::REFUNDED], true) => Entry::STATUS_VOID,
                $lot->status === Entry::STATUS_PENDING => Entry::STATUS_PENDING,
                default => Entry::STATUS_DEPLETED,
            };
        }
        if ($entry->type === Entry::REDEEMED && $remaining === 0) {
            $changes['redeemed_at'] = now();
        }
        if (in_array($entry->type, [Entry::REVERSED, Entry::REFUNDED], true)) {
            $changes['reversed_at'] = now();
        }

        $lot->forceFill($changes)->save();
        LoyaltyAllocation::create(['entry_id' => $entry->id, 'lot_id' => $lot->id, 'points' => $points]);
    }

    /** Expiry date for a lot that becomes usable at $from. */
    public static function expiryFrom(Carbon $from, ?int $validityDays): ?Carbon
    {
        return $validityDays === null ? null : $from->copy()->addDays($validityDays);
    }
}
