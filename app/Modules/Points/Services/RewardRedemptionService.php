<?php

namespace App\Modules\Points\Services;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\LoyaltyLedgerEntry as Entry;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\User;
use App\Modules\Inventory\Services\InventoryService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Exchanging points for rewards, from the customer app or by staff for a customer.
 *
 * redeem() is one transaction under the customer's row lock and the reward's row lock (in that
 * order, every time): checks → reward stock → points (oldest lots first) → redemption → ledger →
 * audit. Two redemptions at once wait for each other, so stock and points cannot be spent twice;
 * a repeated request with the same idempotency key returns the first redemption.
 *
 * Fulfilment hands the reward over (a product reward leaves stock through the inventory ledger);
 * cancellation or expiry before that gives back the stock and the points.
 */
class RewardRedemptionService
{
    public function __construct(private LoyaltyLedger $ledger, private LoyaltyService $loyalty)
    {
    }

    /**
     * @throws DomainException with a message for whoever redeems (InsufficientPoints included)
     */
    public function redeem(User $customer, LoyaltyReward $reward, int $quantity = 1, ?Admin $by = null, ?string $idempotencyKey = null, bool $fulfilNow = false): LoyaltyRedemption
    {
        if ($quantity < 1 || $quantity > 100) {
            throw new DomainException(api_trans('points.reward_quantity'));
        }

        $key = $idempotencyKey ? "u{$customer->id}:".Str::limit($idempotencyKey, 100, '') : null;

        $redemption = DB::transaction(function () use ($customer, $reward, $quantity, $by, $key) {
            $this->ledger->lockCustomer($customer->id);

            if ($key && ($existing = LoyaltyRedemption::where('idempotency_key', $key)->first())) {
                return $existing;
            }

            $reward = LoyaltyReward::withTrashed()->lockForUpdate()->findOrFail($reward->id);
            $settings = $this->loyalty->settings();
            $points = $reward->points_required * $quantity;

            $this->check($customer, $reward, $quantity, $points, $settings);

            $reward->forceFill([
                'stock' => $reward->stock === null ? null : $reward->stock - $quantity,
                'redeemed_count' => $reward->redeemed_count + $quantity,
            ])->save();

            $redemption = LoyaltyRedemption::create([
                'number' => 'TMP-'.Str::random(20),
                'user_id' => $customer->id,
                'reward_id' => $reward->id,
                'source' => LoyaltyRedemption::SOURCE_REWARD,
                'quantity' => $quantity,
                'points' => $points,
                'status' => LoyaltyRedemption::PENDING,
                'reward_snapshot' => $reward->only(['code', 'type', 'title', 'points_required', 'product_id', 'product_size_id', 'terms']),
                'created_by' => $by?->id,
                'expires_at' => ($days = $settings->redemptionValidityDays()) ? now()->addDays($days) : null,
                'idempotency_key' => $key,
            ]);
            $redemption->forceFill(['number' => sprintf('RDM-%06d', $redemption->id)])->save();

            $entry = $this->ledger->debit($customer->id, Entry::REDEEMED, $points, "redeemed:redemption:{$redemption->id}", [
                'reference_type' => 'loyalty_redemption', 'reference_id' => $redemption->id, 'admin_id' => $by?->id,
                'description' => "Reward: {$reward->title}".($quantity > 1 ? " × {$quantity}" : ''),
            ]);
            $redemption->forceFill(['ledger_entry_id' => $entry->id])->save();

            AdminAuditLog::record('loyalty.reward_redeemed', $redemption, [
                'customer_id' => $customer->id, 'reward_id' => $reward->id, 'quantity' => $quantity, 'points' => $points,
                'by' => $by ? 'admin' : 'customer',
            ]);

            return $redemption;
        }, 3);

        return $fulfilNow && $by && $redemption->isOpen() ? $this->fulfil($redemption, $by) : $redemption;
    }

    public function approve(LoyaltyRedemption $redemption, Admin $admin): LoyaltyRedemption
    {
        return DB::transaction(function () use ($redemption, $admin) {
            $redemption = LoyaltyRedemption::lockForUpdate()->findOrFail($redemption->id);

            if ($redemption->status !== LoyaltyRedemption::PENDING) {
                throw new DomainException('Only a pending redemption can be approved.');
            }

            $redemption->forceFill(['status' => LoyaltyRedemption::APPROVED, 'approved_at' => now(), 'approved_by' => $admin->id])->save();
            AdminAuditLog::record('loyalty.redemption_approved', $redemption);

            return $redemption;
        });
    }

    /** Hand the reward over. A product reward leaves the default warehouse as a recorded movement. */
    public function fulfil(LoyaltyRedemption $redemption, ?Admin $admin): LoyaltyRedemption
    {
        return DB::transaction(function () use ($redemption, $admin) {
            $redemption = LoyaltyRedemption::with('reward')->lockForUpdate()->findOrFail($redemption->id);

            if (!$redemption->isOpen() || $redemption->source !== LoyaltyRedemption::SOURCE_REWARD) {
                throw new DomainException('This redemption is not waiting to be handed over.');
            }

            $sizeId = $redemption->reward_snapshot['product_size_id'] ?? null;
            if (($redemption->reward_snapshot['type'] ?? null) === LoyaltyReward::TYPE_PRODUCT && $sizeId) {
                $inventory = app(InventoryService::class);
                try {
                    $inventory->adjust((int) $sizeId, $inventory->defaultWarehouseId(), -$redemption->quantity,
                        "Loyalty reward {$redemption->number}", $admin?->id, $redemption->title());
                } catch (DomainException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    throw new DomainException('The reward product is not in stock: '.$e->getMessage(), 0, $e);
                }
            }

            $redemption->forceFill([
                'status' => LoyaltyRedemption::FULFILLED, 'fulfilled_at' => now(), 'fulfilled_by' => $admin?->id,
                'approved_at' => $redemption->approved_at ?? now(), 'approved_by' => $redemption->approved_by ?? $admin?->id,
            ])->save();
            AdminAuditLog::record('loyalty.redemption_fulfilled', $redemption);

            return $redemption;
        });
    }

    /** Before hand-over only: the reward stock and the points go back. */
    public function cancel(LoyaltyRedemption $redemption, ?Admin $admin, string $reason, string $status = LoyaltyRedemption::CANCELLED): LoyaltyRedemption
    {
        if (trim($reason) === '') {
            throw new DomainException('A reason is required.');
        }

        return DB::transaction(function () use ($redemption, $admin, $reason, $status) {
            $this->ledger->lockCustomer($redemption->user_id);
            $redemption = LoyaltyRedemption::lockForUpdate()->findOrFail($redemption->id);

            if (!$redemption->isOpen()) {
                throw new DomainException($redemption->status === LoyaltyRedemption::FULFILLED
                    ? 'A fulfilled redemption cannot be cancelled.'
                    : 'This redemption was already cancelled or expired.');
            }

            if ($redemption->reward_id) {
                $reward = LoyaltyReward::withTrashed()->lockForUpdate()->find($redemption->reward_id);
                $reward?->forceFill([
                    'stock' => $reward->stock === null ? null : $reward->stock + $redemption->quantity,
                    'redeemed_count' => max(0, $reward->redeemed_count - $redemption->quantity),
                ])->save();
            }

            if ($redemption->ledgerEntry) {
                $this->ledger->restore($redemption->ledgerEntry, "cancelled:redemption:{$redemption->id}", null, [
                    'admin_id' => $admin?->id, 'description' => "Redemption {$redemption->number} {$status}: points returned",
                    'metadata' => ['reason' => $reason],
                ]);
            }

            $redemption->forceFill([
                'status' => $status, 'cancelled_at' => now(), 'cancelled_by' => $admin?->id, 'cancellation_reason' => Str::limit($reason, 250),
            ])->save();

            AdminAuditLog::record($status === LoyaltyRedemption::EXPIRED ? 'loyalty.redemption_expired' : 'loyalty.redemption_cancelled', $redemption, ['reason' => $reason]);

            return $redemption;
        });
    }

    /** Scheduler: redemptions not collected in time expire; their stock and points go back. */
    public function expireStale(): int
    {
        $count = 0;

        LoyaltyRedemption::whereIn('status', LoyaltyRedemption::OPEN)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->chunkById(200, function ($redemptions) use (&$count) {
                foreach ($redemptions as $redemption) {
                    try {
                        $this->cancel($redemption, null, 'Not collected before '.$redemption->expires_at->toDateString(), LoyaltyRedemption::EXPIRED);
                        $count++;
                    } catch (DomainException) {
                        // Changed meanwhile (fulfilled or cancelled by staff): nothing to do.
                    }
                }
            });

        return $count;
    }

    /** Everything that can refuse a redemption, checked under the locks. */
    private function check(User $customer, LoyaltyReward $reward, int $quantity, int $points, LoyaltySettings $settings): void
    {
        if (!$settings->enabled()) {
            throw new DomainException(api_trans('points.programme_off'));
        }
        if (!$customer->isActive()) {
            throw new DomainException(api_trans('points.account_inactive'));
        }
        if (!$reward->isAvailableNow()) {
            throw new DomainException(api_trans('points.reward_unavailable'));
        }
        if (!$reward->inStock($quantity)) {
            throw new DomainException(api_trans('points.reward_out_of_stock'));
        }
        if ($reward->total_limit !== null && $reward->redeemed_count + $quantity > $reward->total_limit) {
            throw new DomainException(api_trans('points.reward_limit_reached'));
        }
        if ($reward->per_customer_limit !== null) {
            $mine = (int) LoyaltyRedemption::where('user_id', $customer->id)->where('reward_id', $reward->id)
                ->whereNotIn('status', [LoyaltyRedemption::CANCELLED, LoyaltyRedemption::EXPIRED])->sum('quantity');
            if ($mine + $quantity > $reward->per_customer_limit) {
                throw new DomainException(api_trans('points.reward_customer_limit', ['limit' => $reward->per_customer_limit]));
            }
        }
        if ($points < $settings->minRedeemPoints()) {
            throw new DomainException(api_trans('points.below_minimum', ['min' => $settings->minRedeemPoints()]));
        }
        if (!$settings->allowMultipleRedemptions() && $this->loyalty->hasOpenRedemption($customer->id)) {
            throw new DomainException(api_trans('points.one_at_a_time'));
        }

        $balance = $this->ledger->balance($customer->id);
        if ($balance < $points) {
            throw new InsufficientPoints($points, $balance);
        }
    }
}
