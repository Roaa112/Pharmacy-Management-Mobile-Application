<?php

namespace App\Modules\Inventory\Services;

use App\Models\InventoryBalance;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockMovement;
use App\Support\DocumentNumber;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Physical inventory counts. Counted units are compared with on_hand at completion time (not
 * when counting started), so sales dispatched meanwhile are not double-counted; the difference
 * is recorded as a count_correction movement. A count below the reserved units is refused:
 * those units are promised to customers and must be resolved first.
 */
class StockCountService
{
    public function __construct(private InventoryService $inventory)
    {
    }

    public function start(int $warehouseId, ?string $notes, ?int $adminId): StockCount
    {
        return DocumentNumber::create(StockCount::class, 'CNT', [
            'warehouse_id' => $warehouseId, 'status' => StockCount::DRAFT, 'notes' => $notes, 'created_by' => $adminId,
        ]);
    }

    /** @param array<int, int|string|null> $counts product_size_id => units counted (blank = not counted) */
    public function record(StockCount $count, array $counts): StockCount
    {
        if ($count->status !== StockCount::DRAFT) {
            throw new DomainException('This count is already '.$count->status.'.');
        }

        DB::transaction(function () use ($count, $counts) {
            foreach ($counts as $sizeId => $counted) {
                if ($counted === null || $counted === '') {
                    continue;
                }
                $counted = filter_var($counted, FILTER_VALIDATE_INT);
                if ($counted === false || $counted < 0) {
                    throw new DomainException('Counted quantities must be whole numbers of 0 or more.');
                }
                StockCountItem::updateOrCreate(['stock_count_id' => $count->id, 'product_size_id' => (int) $sizeId], ['counted_quantity' => $counted]);
            }
        });

        return $count->fresh('items');
    }

    public function complete(StockCount $count, ?int $adminId): StockCount
    {
        return DB::transaction(function () use ($count, $adminId) {
            $count = StockCount::lockForUpdate()->findOrFail($count->id);

            if ($count->status !== StockCount::DRAFT) {
                throw new DomainException('This count is already '.$count->status.'.');
            }
            if (!$count->items()->exists()) {
                throw new DomainException('Record at least one counted quantity before completing the count.');
            }

            foreach ($count->items()->orderBy('product_size_id')->get() as $item) {
                // Lock the SKU first (the shared lock order), then read on_hand.
                \App\Models\ProductSize::lockForUpdate()->find($item->product_size_id);
                $balance = InventoryBalance::firstWhere(['warehouse_id' => $count->warehouse_id, 'product_size_id' => $item->product_size_id]);
                $onHand = (int) ($balance?->on_hand ?? 0);
                $reserved = (int) ($balance?->reserved ?? 0);

                if ($item->counted_quantity < $reserved) {
                    throw new DomainException("SKU #{$item->product_size_id}: counted {$item->counted_quantity} but {$reserved} units are reserved for orders.");
                }

                $difference = $item->counted_quantity - $onHand;
                $item->update(['expected_quantity' => $onHand, 'difference' => $difference]);

                $this->inventory->apply($item->product_size_id, $count->warehouse_id, ['on_hand' => $difference],
                    StockMovement::TYPE_COUNT_CORRECTION,
                    ['reference' => $item, 'admin_id' => $adminId, 'reason' => "{$count->reference}: counted {$item->counted_quantity}, system {$onHand}"],
                    "count:{$item->id}");
            }

            $count->update(['status' => StockCount::COMPLETED, 'completed_by' => $adminId, 'completed_at' => now()]);

            return $count->fresh('items');
        });
    }

    public function cancel(StockCount $count): StockCount
    {
        if ($count->status !== StockCount::DRAFT) {
            throw new DomainException('Only a draft count can be canceled.');
        }
        $count->update(['status' => StockCount::CANCELED]);

        return $count;
    }
}
