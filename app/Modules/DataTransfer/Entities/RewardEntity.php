<?php

namespace App\Modules\DataTransfer\Entities;

use App\Models\AdminAuditLog;
use App\Models\LoyaltyReward;
use App\Models\ProductSize;
use App\Modules\DataTransfer\Import\GroupPlan;
use App\Modules\DataTransfer\Import\ImportContext;
use App\Modules\DataTransfer\Support\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** The loyalty rewards catalog, matched by reward code; a product reward names its size by SKU. */
class RewardEntity extends KeyedModelEntity
{
    public function key(): string { return 'loyalty_rewards'; }

    public function label(): string { return 'Loyalty rewards'; }

    public function description(): string { return 'Rewards customers can redeem with points (not their redemptions).'; }

    public function importPermission(): ?string { return 'loyalty_rewards.import'; }

    public function exportPermission(): ?string { return 'loyalty_rewards.export'; }

    protected function model(): string { return LoyaltyReward::class; }

    protected function keyColumn(): string { return 'code'; }

    protected function imageColumns(): array { return ['image_url' => 'rewards']; }

    public function columns(): array
    {
        return [
            'code' => Column::make('Code', 'Unique reward code.', true, 'Letters, numbers, - and _ (max 40)', 'RW-VITC'),
            'type' => Column::make('Type', '', true, implode(' | ', LoyaltyReward::TYPES), 'product'),
            'title' => Column::make('Title', '', true, 'Text, max 255', 'Vitamin C 500 mg'),
            'description' => Column::make('Description', '', false, 'Text', ''),
            'product_sku' => Column::make('Product size SKU', 'Product rewards: the SKU of the size handed over.', false, 'Existing size SKU', 'VITC-500-30'),
            'points_required' => Column::make('Points required', '', true, 'Whole number ≥ 1', '250'),
            'stock' => Column::make('Stock', 'Empty: unlimited.', false, 'Whole number ≥ 0', '20'),
            'is_active' => Column::make('Active', '', false, '1 / 0', '1'),
            'starts_at' => Column::make('Available from', '', false, 'Date/time (YYYY-MM-DD HH:MM)', ''),
            'ends_at' => Column::make('Available until', '', false, 'Date/time', ''),
            'per_customer_limit' => Column::make('Limit per customer', '', false, 'Whole number ≥ 1', '2'),
            'total_limit' => Column::make('Total limit', '', false, 'Whole number ≥ 1', ''),
            'terms' => Column::make('Terms', '', false, 'Text', ''),
            'sort_order' => Column::make('Sort order', '', false, 'Whole number', '1'),
            'image_url' => Column::make('Image', 'Optional: http(s) URL or existing path.', false, 'URL or path', ''),
            'redeemed_count' => Column::exportOnly('Units redeemed'),
            'created_at' => Column::exportOnly('Created at'),
        ];
    }

    protected function rules(?Model $existing): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'type' => [$existing ? 'nullable' : 'required', Rule::in(LoyaltyReward::TYPES)],
            'title' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'product_sku' => ['nullable', 'string', Rule::exists('product_sizes', 'sku')],
            'points_required' => [$existing ? 'nullable' : 'required', 'integer', 'min:1', 'max:10000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'is_active' => ['nullable', 'in:0,1,true,false,yes,no'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'total_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
            'image_url' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function afterValidate(GroupPlan $plan, ImportContext $context, array $data, ?Model $existing): void
    {
        $type = $data['type'] ?? $existing?->type;
        if ($type === LoyaltyReward::TYPE_PRODUCT && empty($data['product_sku']) && !$existing?->product_size_id) {
            $plan->error($plan->firstRow(), 'product_sku', 'A product reward needs the SKU of the size it gives.');
        }
    }

    protected function attributes(array $data, ?Model $existing, GroupPlan $plan): array
    {
        $attributes = self::filled(array_intersect_key($data, array_flip([
            'type', 'title', 'description', 'points_required', 'stock', 'starts_at', 'ends_at', 'per_customer_limit', 'total_limit', 'terms', 'sort_order',
        ])), $existing) + ['code' => strtoupper($data['code'])];

        if (($data['is_active'] ?? null) !== null || !$existing) {
            $attributes['is_active'] = self::bool($data['is_active'] ?? null);
        }
        if (!empty($data['product_sku'])) {
            $size = ProductSize::where('sku', $data['product_sku'])->first();
            $attributes['product_size_id'] = $size->id;
            $attributes['product_id'] = $size->product_id;
        }

        return $attributes;
    }

    public function persist(GroupPlan $plan, ImportContext $context): void
    {
        parent::persist($plan, $context);
        $reward = LoyaltyReward::where('code', $plan->identifier)->first();
        AdminAuditLog::record($plan->action === GroupPlan::CREATE ? 'loyalty.reward_create' : 'loyalty.reward_update', $reward, ['import_run_id' => $context->runId], $context->adminId);
    }

    public function filters(): array
    {
        return ['type' => ['Type', 'select', array_combine(LoyaltyReward::TYPES, LoyaltyReward::TYPES)], 'is_active' => ['Status', 'select', ['1' => 'Active', '0' => 'Inactive']]];
    }

    public function exportQuery(array $filters): Builder
    {
        return LoyaltyReward::query()->with('productSize:id,sku')
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when(isset($filters['is_active']) && $filters['is_active'] !== '', fn ($q) => $q->where('is_active', (bool) $filters['is_active']));
    }

    public function exportRow(Model $reward): array
    {
        return [
            'code' => $reward->code, 'type' => $reward->type, 'title' => $reward->title, 'description' => $reward->description,
            'product_sku' => $reward->productSize?->sku, 'points_required' => $reward->points_required, 'stock' => $reward->stock,
            'is_active' => $reward->is_active ? 1 : 0, 'starts_at' => $reward->starts_at, 'ends_at' => $reward->ends_at,
            'per_customer_limit' => $reward->per_customer_limit, 'total_limit' => $reward->total_limit, 'terms' => $reward->terms,
            'sort_order' => $reward->sort_order, 'image_url' => $reward->image, 'redeemed_count' => $reward->redeemed_count, 'created_at' => $reward->created_at,
        ];
    }

    public function examples(): array
    {
        return [
            ['code' => 'RW-VITC', 'type' => 'product', 'title' => 'Vitamin C 500 mg', 'product_sku' => 'VITC-500-30', 'points_required' => '250', 'stock' => '20', 'is_active' => '1'],
            ['code' => 'RW-DELIVERY', 'type' => 'free_delivery', 'title' => 'Free delivery', 'points_required' => '150', 'is_active' => '1'],
        ];
    }
}
