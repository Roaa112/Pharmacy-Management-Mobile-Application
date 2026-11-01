<?php

namespace App\Modules\Promotions\Services;

use App\Models\AdminAuditLog;
use App\Models\DiscountRule;
use App\Models\DiscountRuleTarget;
use App\Models\PromotionReward;
use App\Models\User;
use App\Modules\Promotions\Engine\PromotionCatalog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, edits, activates, duplicates and archives promotions from the dashboard, in one
 * transaction each, with the domain validation (PromotionValidator) and an audit log entry.
 * An active promotion with a critical configuration error is never saved.
 */
class PromotionAdminService
{
    public const FIELDS = [
        'name_en', 'name_ar', 'description_en', 'description_ar', 'campaign_id', 'discount_type', 'discount_value',
        'min_amount', 'min_quantity', 'starts_at', 'ends_at', 'is_active', 'priority', 'is_exclusive', 'is_stackable',
        'repeatable', 'max_applications_per_order', 'usage_limit', 'per_customer_limit', 'customer_scope',
        'min_order_amount', 'max_order_amount', 'max_discount_amount', 'qualifying_basis', 'count_delivery_fee',
        'coupon_reduces_qualifying', 'tier_mode', 'gift_fallback',
    ];

    public function __construct(private PromotionValidator $validator)
    {
    }

    public function save(array $data, ?DiscountRule $promotion = null, ?int $adminId = null): DiscountRule
    {
        return DB::transaction(function () use ($data, $promotion, $adminId) {
            $creating = $promotion === null;
            $promotion ??= new DiscountRule(['created_by' => $adminId]);
            $before = $creating ? [] : $this->snapshot($promotion->load(['targets', 'rewards']));

            $attributes = Arr::only($data, self::FIELDS);
            // Older columns some screens still read: the first target.
            $first = $data['targets'][0] ?? null;
            $attributes['applies_to_type'] = $first['type'] ?? null;
            $attributes['applies_to_id'] = $first['id'] ?? null;
            $attributes['updated_by'] = $adminId;

            $promotion->fill($attributes)->save();

            if (array_key_exists('targets', $data) || array_key_exists('gift_targets', $data)) {
                $promotion->targets()->delete();
                foreach ($data['targets'] ?? [] as $target) {
                    $promotion->targets()->create(['target_type' => $target['type'], 'target_id' => (int) $target['id'], 'is_excluded' => (bool) ($target['excluded'] ?? false)]);
                }
            }

            if (array_key_exists('rewards', $data)) {
                $this->syncRewards($promotion, $data['rewards'] ?? []);
            }

            // Legacy form field: a gift target becomes a free-product reward (DiscountRuleTarget hook).
            foreach ($data['gift_targets'] ?? [] as $gift) {
                DiscountRuleTarget::create(['discount_rule_id' => $promotion->id, 'target_type' => $gift['type'], 'target_id' => (int) $gift['id'], 'is_gift' => true]);
            }

            if (array_key_exists('customer_emails', $data) || array_key_exists('customer_ids', $data)) {
                $promotion->customers()->sync($this->customerIds($data));
            }

            $promotion->unsetRelations();
            $this->assertValid($promotion);

            AdminAuditLog::record($creating ? 'promotion.created' : 'promotion.updated', $promotion, ['before' => $before, 'after' => $this->snapshot($promotion->load(['targets', 'rewards']))]);
            // Customer list changes are pivot writes (no model event): forget the cached state.
            PromotionCatalog::flush();

            return $promotion;
        });
    }

    public function setActive(DiscountRule $promotion, bool $active, ?int $adminId): DiscountRule
    {
        return DB::transaction(function () use ($promotion, $active, $adminId) {
            $promotion->forceFill(['is_active' => $active, 'updated_by' => $adminId])->save();
            $this->assertValid($promotion);
            AdminAuditLog::record($active ? 'promotion.activated' : 'promotion.deactivated', $promotion);

            return $promotion;
        });
    }

    /** A copy as an inactive draft, with the same targets, rewards and customers. */
    public function duplicate(DiscountRule $promotion, ?int $adminId): DiscountRule
    {
        return DB::transaction(function () use ($promotion, $adminId) {
            $promotion->load(['targets', 'rewards', 'customers:id']);
            $copy = $promotion->replicate(['deleted_at']);
            $copy->fill([
                'name_en' => trim(($promotion->name_en ?: $promotion->name).' (copy)'),
                'name_ar' => $promotion->name_ar ? $promotion->name_ar.' (نسخة)' : null,
                'is_active' => false,
                'created_by' => $adminId,
                'updated_by' => $adminId,
            ])->save();

            foreach ($promotion->targets->where('is_gift', false) as $target) {
                $copy->targets()->create($target->only(['target_type', 'target_id', 'is_excluded']));
            }
            foreach ($promotion->rewards as $reward) {
                $copy->rewards()->create($reward->only(['threshold_amount', 'reward_type', 'product_id', 'product_size_id', 'quantity', 'value', 'max_discount_amount', 'sort_order']));
            }
            $copy->customers()->sync($promotion->customers->pluck('id'));
            PromotionCatalog::flush();

            AdminAuditLog::record('promotion.duplicated', $copy, ['from' => $promotion->id]);

            return $copy;
        });
    }

    /** Archive (soft delete): orders, redemptions and snapshots keep pointing at it. */
    public function archive(DiscountRule $promotion): void
    {
        DB::transaction(function () use ($promotion) {
            $promotion->forceFill(['is_active' => false])->save();
            $promotion->delete();
            AdminAuditLog::record('promotion.archived', $promotion);
        });
    }

    /** @throws ValidationException when an active promotion has critical configuration errors */
    public function assertValid(DiscountRule $promotion): void
    {
        // Read back: a promotion saved without is_active is active by the column default.
        $fresh = $promotion->fresh();
        if (!$fresh?->is_active) {
            return;
        }

        $errors = $this->validator->check($fresh)['errors'];
        if ($errors) {
            throw ValidationException::withMessages(['is_active' => array_merge(['This promotion cannot be active until these are fixed:'], $errors)]);
        }
    }

    private function syncRewards(DiscountRule $promotion, array $rewards): void
    {
        $keep = [];

        foreach (array_values($rewards) as $index => $reward) {
            $attributes = [
                'reward_type' => $reward['reward_type'],
                'threshold_amount' => $reward['threshold_amount'] ?? null,
                'product_id' => $reward['reward_type'] === PromotionReward::FREE_PRODUCT ? ($reward['product_id'] ?? null) : null,
                'product_size_id' => $reward['reward_type'] === PromotionReward::FREE_PRODUCT ? ($reward['product_size_id'] ?? null) : null,
                'quantity' => max(1, (int) ($reward['quantity'] ?? 1)),
                'value' => in_array($reward['reward_type'], [PromotionReward::FIXED_DISCOUNT, PromotionReward::PERCENT_DISCOUNT], true) ? ($reward['value'] ?? null) : null,
                'max_discount_amount' => $reward['max_discount_amount'] ?? null,
                'sort_order' => $index,
            ];

            $existing = !empty($reward['id']) ? $promotion->rewards()->whereKey($reward['id'])->first() : null;
            $model = $existing ? tap($existing)->update($attributes) : $promotion->rewards()->create($attributes);
            $keep[] = $model->id;
        }

        $promotion->rewards()->whereNotIn('id', $keep ?: [0])->delete();
    }

    private function customerIds(array $data): array
    {
        $ids = array_map('intval', $data['customer_ids'] ?? []);
        $emails = preg_split('/[\s,;]+/', (string) ($data['customer_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

        if ($emails) {
            $ids = array_merge($ids, User::whereIn('email', $emails)->pluck('id')->all());
        }

        return array_values(array_unique($ids));
    }

    private function snapshot(DiscountRule $promotion): array
    {
        return Arr::only($promotion->toArray(), array_merge(self::FIELDS, ['id'])) + [
            'targets' => $promotion->targets->map(fn ($t) => $t->only(['target_type', 'target_id', 'is_excluded', 'is_gift']))->values()->all(),
            'rewards' => $promotion->rewards->map(fn ($r) => $r->only(['reward_type', 'threshold_amount', 'product_id', 'product_size_id', 'quantity', 'value']))->values()->all(),
        ];
    }
}
