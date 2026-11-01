<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a promotion applies to: a product, a variant (product size), a brand or a category.
 * is_excluded removes matching items from a broader target; is_gift is the legacy way of
 * naming a gift (now promotion_rewards).
 */
class DiscountRuleTarget extends Model
{
    public const TYPES = ['product', 'variant', 'brand', 'category'];

    protected $fillable = ['discount_rule_id', 'target_type', 'target_id', 'is_gift', 'is_excluded'];

    protected $casts = ['is_gift' => 'boolean', 'is_excluded' => 'boolean', 'target_id' => 'integer'];

    protected static function booted()
    {
        // A product named the legacy way (is_gift target) becomes the promotion's explicit
        // free-product reward, the same conversion the 2026_11_07 migration did for existing
        // rules. Brand/category gift targets name no product and get no reward (decision D12).
        static::created(function (DiscountRuleTarget $target) {
            $rule = $target->discountRule;

            if (!$target->is_gift || $target->target_type !== 'product' || !$rule || !in_array($rule->discount_type, DiscountRule::GIFT_TYPES, true)) {
                return;
            }

            PromotionReward::firstOrCreate(
                ['discount_rule_id' => $rule->id, 'reward_type' => PromotionReward::FREE_PRODUCT, 'product_id' => $target->target_id, 'product_size_id' => null],
                [
                    'threshold_amount' => $rule->discount_type === DiscountRule::TYPE_SPEND_X_GET_Y ? $rule->min_amount : null,
                    'quantity' => max(1, (int) ($rule->gift_quantity ?: $rule->free_quantity ?: 1)),
                ],
            );
        });
    }

    public function discountRule()
    {
        return $this->belongsTo(DiscountRule::class);
    }
}
