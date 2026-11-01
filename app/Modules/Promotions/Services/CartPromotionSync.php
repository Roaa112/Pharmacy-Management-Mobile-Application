<?php

namespace App\Modules\Promotions\Services;

use App\Models\Cart;
use App\Models\PromotionReward;
use App\Models\User;
use App\Modules\Promotions\Engine\PricingResult;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Makes the customer's gift lines exactly what the engine says, after every cart change.
 *
 *  - one gift line per promotion reward (gift_key "p{promotion}:r{reward}", unique per user),
 *    so repeated recalculation never duplicates a gift;
 *  - a gift the customer still earns is updated in place (quantity, variant);
 *  - a gift no longer earned is deleted, with the reason (quantity or spend no longer
 *    reached, promotion ended, out of stock...);
 *  - customer lines are never touched here; their prices are refreshed by the caller.
 *
 * The backend is the source of truth: the app cannot add, edit or keep a gift line.
 */
class CartPromotionSync
{
    /** @return list<array> gift changes for the response (added / updated / removed) */
    public function sync(User $user, PricingResult $result): array
    {
        return DB::transaction(function () use ($user, $result) {
            $existing = Cart::giftLines()->where('user_id', $user->id)->lockForUpdate()->get()->keyBy('gift_key');
            $changes = [];
            $keep = [];

            foreach ($result->gifts as $gift) {
                $size = $gift['size'];
                $attributes = [
                    'product_id' => $size->product_id,
                    'product_size_id' => $size->id,
                    'product_quantity' => $gift['quantity'],
                    'price_at_time' => 0,
                    'original_price' => Money::fromMinor($gift['original_unit']),
                    'total_price' => 0,
                    'is_gift' => true,
                    'discount_rule_id' => $gift['promotion']->id,
                    'promotion_reward_id' => $gift['reward']->id,
                ];

                $line = $existing->get($gift['gift_key']);
                $keep[] = $gift['gift_key'];

                if (!$line) {
                    Cart::create($attributes + ['user_id' => $user->id, 'gift_key' => $gift['gift_key']]);
                    $changes[] = $this->change('added', $gift['gift_key'], $gift['promotion'], $size->product?->name, $size->id, $gift['quantity'], 0, null);
                    continue;
                }

                $previous = (int) $line->product_quantity;
                $line->fill($attributes);
                if ($line->isDirty(['product_quantity', 'product_size_id'])) {
                    $changes[] = $this->change('updated', $gift['gift_key'], $gift['promotion'], $size->product?->name, $size->id, $gift['quantity'], $previous,
                        $gift['status'] === 'partial' ? 'gift_partially_available' : null);
                }
                $line->save();
            }

            // toBase(): an Eloquent collection's except() filters by primary key, not by gift key.
            foreach ($existing->toBase()->except($keep) as $key => $line) {
                $rejection = $line->discount_rule_id ? $result->rejectionFor('promotion', (int) $line->discount_rule_id) : null;
                $reason = $rejection['reason'] ?? $this->tierReason($result, $line) ?? 'promotion_ended';
                $changes[] = $this->change('removed', $key, $line->promotion, $line->product?->name, $line->product_size_id, 0, (int) $line->product_quantity, $reason,
                    $rejection['message'] ?? null);
                $line->delete();
            }

            return $changes;
        });
    }

    /**
     * The promotion still applies but no longer gives this reward: its tier is no longer reached
     * (spend went down), or a higher tier replaced it ("highest tier" promotions).
     */
    private function tierReason(PricingResult $result, Cart $line): ?string
    {
        $applied = collect($result->applied)->first(fn ($a) => $a['source'] === 'promotion' && $a['id'] === (int) $line->discount_rule_id);
        if (!$applied) {
            return null;
        }

        $reward = PromotionReward::find($line->promotion_reward_id);
        $threshold = Money::toMinor($reward?->threshold_amount ?? $applied['model']->min_amount ?? 0);

        return $applied['eligible'] < $threshold ? 'minimum_spend_not_reached' : 'reward_replaced';
    }

    private function change(string $type, string $key, $promotion, ?string $product, ?int $sizeId, int $quantity, int $previous, ?string $reason, ?string $message = null): array
    {
        $reasonMessage = $reason ? ($message ?? api_trans("promotions.reasons.{$reason}")) : null;

        return [
            'type' => $type,
            'gift_key' => $key,
            'promotion_id' => $promotion?->id,
            'promotion_name' => $promotion?->name,
            'product_name' => $product,
            'product_size_id' => $sizeId,
            'quantity' => $quantity,
            'previous_quantity' => $previous,
            'reason' => $reason,
            'message' => trim(api_trans("promotions.gift.{$type}", ['product' => (string) $product]).($reasonMessage ? ' — '.$reasonMessage : '')),
        ];
    }
}
