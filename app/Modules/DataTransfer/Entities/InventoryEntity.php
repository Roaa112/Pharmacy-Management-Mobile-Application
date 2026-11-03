<?php

namespace App\Modules\DataTransfer\Entities;

use App\Models\InventoryBalance;
use App\Models\ProductSize;
use App\Models\Warehouse;
use App\Modules\DataTransfer\Import\GroupPlan;
use App\Modules\DataTransfer\Import\ImportContext;
use App\Modules\DataTransfer\Support\Column;
use App\Modules\Inventory\Services\InventoryService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock per size and warehouse. Exported from inventory_balances; imported as stock
 * adjustments through InventoryService::adjust (a ledger movement with a reason, the same as
 * the Adjust stock screen), never by writing quantities. A row either sets the counted on-hand
 * quantity (set_on_hand) or moves it by a signed amount (adjust_by).
 */
class InventoryEntity extends DataEntity
{
    public function key(): string { return 'inventory'; }

    public function label(): string { return 'Inventory'; }

    public function description(): string { return 'Units on hand per size and warehouse. Imports are recorded as stock adjustments.'; }

    public function importPermission(): ?string { return 'inventory.import'; }

    public function exportPermission(): ?string { return 'inventory.export'; }

    public function modes(): array
    {
        return ['update'];
    }

    public function matchDescription(): string
    {
        return 'Sizes by sku (or barcode); warehouse by warehouse_code (empty = default warehouse). Each row is an adjustment.';
    }

    public function columns(): array
    {
        return [
            'sku' => Column::make('SKU', 'The size\'s SKU (or give barcode).', false, 'Existing SKU', 'VITC-500-30'),
            'barcode' => Column::make('Barcode', 'Used when sku is empty.', false, 'Existing barcode', ''),
            'warehouse_code' => Column::make('Warehouse code', 'Empty: the default warehouse.', false, 'Existing warehouse code', 'MAIN'),
            'set_on_hand' => Column::make('Set on hand to', 'The counted quantity. Fill this or adjust_by.', false, 'Whole number ≥ 0', '40'),
            'adjust_by' => Column::make('Adjust by', 'Signed change (+5 received, -2 lost). Fill this or set_on_hand.', false, 'Whole number', ''),
            'reason' => Column::make('Reason', 'Recorded on the movement. Empty: "Stock import #id".', false, 'Text, max 190', 'Quarterly count'),
            'product_code' => Column::exportOnly('Product code'),
            'product_name' => Column::exportOnly('Product'),
            'size' => Column::exportOnly('Size'),
            'on_hand' => Column::exportOnly('On hand'),
            'reserved' => Column::exportOnly('Reserved'),
            'available' => Column::exportOnly('Available'),
            'damaged' => Column::exportOnly('Damaged'),
            'quarantined' => Column::exportOnly('Quarantined'),
            'in_transit' => Column::exportOnly('In transit'),
        ];
    }

    public function requiredHeaders(): array
    {
        return [];
    }

    public function filters(): array
    {
        return ['warehouse_id' => ['Warehouse', 'select', Warehouse::orderBy('name')->pluck('name', 'id')->all()], 'only_stocked' => ['Only rows with stock', 'boolean']];
    }

    public function exportQuery(array $filters): Builder
    {
        return InventoryBalance::query()->with(['warehouse:id,code', 'size:id,product_id,sku,barcode,size', 'size.product' => fn ($q) => $q->withoutGlobalScope('listing_aggregates')->withTrashed()->without(['sizes', 'saleable'])->select('id', 'code', 'name_en')])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when(!empty($filters['only_stocked']), fn ($q) => $q->where(fn ($w) => $w->where('on_hand', '>', 0)->orWhere('damaged', '>', 0)->orWhere('quarantined', '>', 0)));
    }

    public function exportRow(Model $balance): array
    {
        return [
            'sku' => $balance->size?->sku, 'barcode' => $balance->size?->barcode, 'warehouse_code' => $balance->warehouse?->code,
            'set_on_hand' => $balance->on_hand, 'adjust_by' => '', 'reason' => '',
            'product_code' => $balance->size?->product?->code, 'product_name' => $balance->size?->product?->name_en, 'size' => $balance->size?->size,
            'on_hand' => $balance->on_hand, 'reserved' => $balance->reserved, 'available' => max(0, $balance->on_hand - $balance->reserved),
            'damaged' => $balance->damaged, 'quarantined' => $balance->quarantined, 'in_transit' => $balance->in_transit,
        ];
    }

    public function examples(): array
    {
        return [
            ['sku' => 'VITC-500-30', 'warehouse_code' => 'MAIN', 'set_on_hand' => '40', 'reason' => 'Quarterly count'],
            ['sku' => 'VITC-500-60', 'adjust_by' => '-2', 'reason' => 'Broken in storage'],
        ];
    }

    public function notes(): array
    {
        return ['Every row becomes a stock adjustment movement with its reason (Inventory › Stock movements).', 'Rows whose quantity is already right are skipped.'];
    }

    public function validate(GroupPlan $plan, ImportContext $context): void
    {
        $number = $plan->firstRow();
        $row = $plan->rows[$number];
        $data = $this->check($plan, $number, [
            'sku' => self::value($row, 'sku'), 'barcode' => self::value($row, 'barcode'), 'warehouse_code' => self::value($row, 'warehouse_code'),
            'set_on_hand' => self::value($row, 'set_on_hand'), 'adjust_by' => self::value($row, 'adjust_by'), 'reason' => self::value($row, 'reason'),
        ], [
            'sku' => ['nullable', 'string', 'max:64', 'required_without:barcode'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'warehouse_code' => ['nullable', 'string', 'max:30'],
            'set_on_hand' => ['nullable', 'integer', 'min:0', 'max:10000000', 'required_without:adjust_by', 'prohibits:adjust_by'],
            'adjust_by' => ['nullable', 'integer', 'min:-10000000', 'max:10000000', 'not_in:0'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        if ($plan->failed()) {
            return;
        }

        $size = !empty($data['sku']) ? ProductSize::where('sku', $data['sku'])->first() : ProductSize::where('barcode', $data['barcode'])->first();
        if (!$size) {
            $plan->error($number, !empty($data['sku']) ? 'sku' : 'barcode', 'No size with this '.(!empty($data['sku']) ? 'SKU' : 'barcode').'.', $data['sku'] ?? $data['barcode']);

            return;
        }

        $warehouse = !empty($data['warehouse_code']) ? Warehouse::where('code', $data['warehouse_code'])->first() : Warehouse::find(app(InventoryService::class)->defaultWarehouseId());
        if (!$warehouse) {
            $plan->error($number, 'warehouse_code', 'No warehouse with this code.', $data['warehouse_code']);

            return;
        }

        if ($other = $context->claim('size-warehouse', $size->id.'@'.$warehouse->id, $number)) {
            $plan->error($number, 'sku', "This size and warehouse already appear on row {$other}; use one row per size and warehouse.", $data['sku'] ?? '');

            return;
        }

        $onHand = (int) InventoryBalance::where(['product_size_id' => $size->id, 'warehouse_id' => $warehouse->id])->value('on_hand');
        $change = isset($data['set_on_hand']) && $data['set_on_hand'] !== null ? (int) $data['set_on_hand'] - $onHand : (int) $data['adjust_by'];

        if ($onHand + $change < 0) {
            $plan->error($number, 'adjust_by', "Would leave {$warehouse->code} with less than 0 units (on hand {$onHand}).", (string) $change);

            return;
        }

        $plan->identifier = ($size->sku ?? $size->barcode).' @ '.$warehouse->code;
        $plan->action = $change === 0 ? GroupPlan::SKIP : GroupPlan::UPDATE;
        if ($change === 0) {
            $plan->warning($number, 'set_on_hand', 'Already at this quantity: skipped.');
        }
        $plan->payload = [
            'size_id' => $size->id, 'warehouse_id' => $warehouse->id, 'set' => $data['set_on_hand'] ?? null, 'adjust' => $data['adjust_by'] ?? null,
            'reason' => $data['reason'] ?? null,
        ];
    }

    public function persist(GroupPlan $plan, ImportContext $context): void
    {
        $p = $plan->payload;
        $inventory = app(InventoryService::class);

        // The SKU row lock serializes this with every other stock change; the difference is
        // computed from the quantity read under it (a concurrent sale is never overwritten).
        ProductSize::lockForUpdate()->findOrFail($p['size_id']);
        $onHand = (int) InventoryBalance::where(['product_size_id' => $p['size_id'], 'warehouse_id' => $p['warehouse_id']])->value('on_hand');
        $change = $p['set'] !== null ? (int) $p['set'] - $onHand : (int) $p['adjust'];

        if ($change === 0) {
            return;
        }
        if ($onHand + $change < 0) {
            throw new DomainException("Stock changed meanwhile: on hand is now {$onHand}.");
        }

        $inventory->adjust($p['size_id'], $p['warehouse_id'], $change, $p['reason'] ?: "Stock import #{$context->runId}", $context->adminId, "Import #{$context->runId}");
    }
}
