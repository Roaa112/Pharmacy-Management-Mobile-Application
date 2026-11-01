<?php

namespace App\Modules\Inventory\Services;

use App\Models\InventoryBalance;
use App\Models\OrderItem;
use App\Models\ProductSize;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only code that changes stock. Every change:
 *  - runs in a transaction and locks the SKU row (product_sizes) first, so all changes to one
 *    SKU are serialized, whichever warehouse they touch (one lock order: no deadlocks);
 *  - writes one immutable stock_movements row per bucket it changes, with before/after;
 *  - carries an idempotency key per bucket (unique in the database): repeating the same
 *    business event changes nothing;
 *  - refuses any bucket below zero and reserved above on_hand (no negative stock, no overselling);
 *  - refreshes the product_sizes.stock / quarantined_stock projections the catalogue reads.
 *
 * Buckets: on_hand (sellable condition, physically present), reserved (part of on_hand held
 * for orders), damaged, quarantined (customer returns awaiting inspection), in_transit.
 */
class InventoryService
{
    /**
     * Apply bucket changes to one SKU in one warehouse.
     *
     * @param  array<string, int>  $deltas  bucket => signed change
     * @param  array{reference?: Model, user_id?: int, admin_id?: int, reason?: string, notes?: string, unit_cost?: mixed}  $context
     * @return Collection<int, StockMovement> the rows written (empty when the key was already applied)
     */
    public function apply(int $sizeId, int $warehouseId, array $deltas, string $type, array $context, string $key): Collection
    {
        $deltas = array_filter($deltas, fn ($change) => $change !== 0);

        foreach (array_keys($deltas) as $bucket) {
            if (!in_array($bucket, InventoryBalance::BUCKETS, true)) {
                throw new DomainException("Unknown stock bucket {$bucket}.");
            }
        }

        if (!$deltas) {
            return collect();
        }

        return DB::transaction(function () use ($sizeId, $warehouseId, $deltas, $type, $context, $key) {
            $size = ProductSize::lockForUpdate()->findOrFail($sizeId);

            $keys = array_map(fn ($bucket) => "{$key}:{$bucket}", array_keys($deltas));
            if (StockMovement::whereIn('idempotency_key', $keys)->exists()) {
                return collect(); // already applied
            }

            $balance = $this->lockedBalance($warehouseId, $sizeId);
            $before = $balance->only(InventoryBalance::BUCKETS);
            $after = $before;

            foreach ($deltas as $bucket => $change) {
                $after[$bucket] += $change;

                if ($after[$bucket] < 0) {
                    throw new InsufficientStock($size, $warehouseId, $bucket, $before[$bucket], -$change);
                }
            }

            // Reserved units are part of on_hand: available (on_hand - reserved) never goes negative.
            if ($after['reserved'] > $after['on_hand']) {
                $availableBefore = $before['on_hand'] - $before['reserved'];
                throw new InsufficientStock($size, $warehouseId, 'available', $availableBefore, $availableBefore - ($after['on_hand'] - $after['reserved']));
            }

            InventoryBalance::whereKey($balance->id)->update($after + ['updated_at' => now()]);

            $reference = $context['reference'] ?? null;
            $movements = collect();

            foreach ($deltas as $bucket => $change) {
                $movements->push(StockMovement::create([
                    'product_id' => $size->product_id,
                    'product_size_id' => $sizeId,
                    'warehouse_id' => $warehouseId,
                    'type' => $type,
                    'bucket' => $bucket,
                    'quantity_before' => $before[$bucket],
                    'quantity_change' => $change,
                    'quantity_after' => $after[$bucket],
                    'unit_cost' => $context['unit_cost'] ?? $size->cost_price,
                    'reference_type' => $reference?->getMorphClass(),
                    'reference_id' => $reference?->getKey(),
                    'idempotency_key' => "{$key}:{$bucket}",
                    'user_id' => $context['user_id'] ?? null,
                    'admin_id' => $context['admin_id'] ?? null,
                    'reason' => $context['reason'] ?? null,
                    'notes' => $context['notes'] ?? null,
                ]));
            }

            $this->refreshProjection($size);

            return $movements;
        });
    }

    /** Units that can be sold now from sellable warehouses, per warehouse (default first). */
    public function availability(int $sizeId): Collection
    {
        return DB::table('inventory_balances')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_balances.warehouse_id')
            ->where('inventory_balances.product_size_id', $sizeId)
            ->where('warehouses.is_active', true)->where('warehouses.is_sellable', true)
            ->orderByDesc('warehouses.is_default')
            ->orderByRaw('(inventory_balances.on_hand - inventory_balances.reserved) DESC')
            ->orderBy('warehouses.id')
            ->get(['inventory_balances.warehouse_id', DB::raw('(inventory_balances.on_hand - inventory_balances.reserved) as available')])
            ->filter(fn ($row) => $row->available > 0)
            ->mapWithKeys(fn ($row) => [(int) $row->warehouse_id => (int) $row->available]);
    }

    // ------------------------------------------------------------------ orders

    /**
     * Hold units of an order line: default sellable warehouse first, then the warehouse with
     * the most available units. Throws InsufficientStock if all sellable warehouses together
     * do not have enough (the caller's transaction then rolls back).
     */
    public function reserve(OrderItem $item, array $context = []): void
    {
        DB::transaction(function () use ($item, $context) {
            ProductSize::lockForUpdate()->findOrFail($item->product_size_id);
            $remaining = (int) $item->quantity;

            foreach ($this->availability($item->product_size_id) as $warehouseId => $available) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, $available);
                $reservation = StockReservation::create([
                    'order_item_id' => $item->id, 'product_size_id' => $item->product_size_id,
                    'warehouse_id' => $warehouseId, 'quantity' => $take, 'status' => StockReservation::ACTIVE,
                ]);

                $this->apply($item->product_size_id, $warehouseId, ['reserved' => $take], StockMovement::TYPE_RESERVATION,
                    $context + ['reference' => $reservation, 'reason' => $context['reason'] ?? "Order #{$item->order_id}"],
                    "reservation:{$reservation->id}");

                $remaining -= $take;
            }

            if ($remaining > 0) {
                throw new InsufficientStock(ProductSize::find($item->product_size_id), null, 'available', (int) $item->quantity - $remaining, (int) $item->quantity);
            }

            $item->forceFill(['stock_state' => OrderItem::STOCK_RESERVED])->save();
        });
    }

    /** The reserved units leave the warehouse (dispatch, or hand-over for pickup orders). */
    public function fulfil(OrderItem $item, array $context = []): void
    {
        DB::transaction(function () use ($item, $context) {
            ProductSize::lockForUpdate()->find($item->product_size_id);
            $item = OrderItem::lockForUpdate()->findOrFail($item->id);

            if ($item->stock_state !== OrderItem::STOCK_RESERVED) {
                return; // legacy lines left stock at checkout; fulfilled lines are done
            }

            $type = $item->is_gift ? StockMovement::TYPE_GIFT : StockMovement::TYPE_FULFILLMENT;

            foreach ($this->activeReservations($item) as $reservation) {
                $this->apply($item->product_size_id, $reservation->warehouse_id,
                    ['on_hand' => -$reservation->quantity, 'reserved' => -$reservation->quantity], $type,
                    $context + ['reference' => $reservation, 'reason' => $context['reason'] ?? "Order #{$item->order_id} dispatched"],
                    "fulfillment:{$reservation->id}");
                $reservation->update(['status' => StockReservation::FULFILLED, 'fulfilled_at' => now()]);
            }

            $item->forceFill(['stock_state' => OrderItem::STOCK_FULFILLED])->save();
        });
    }

    /**
     * Stock side of a cancellation, once per line:
     *  - reserved: the reservation is released (the units never left);
     *  - legacy line (stock taken at checkout, never reserved): units go back on hand, as before;
     *  - fulfilled: the units left the warehouse; they come back to quarantine for inspection;
     *  - released / restocked / returned: nothing left to do.
     */
    public function releaseForCancellation(OrderItem $item, array $context = []): void
    {
        DB::transaction(function () use ($item, $context) {
            ProductSize::lockForUpdate()->find($item->product_size_id);
            $item = OrderItem::lockForUpdate()->findOrFail($item->id);
            $reason = $context['reason'] ?? "Order #{$item->order_id} canceled";

            match ($item->stock_state) {
                OrderItem::STOCK_RESERVED => $this->releaseReservations($item, $context + ['reason' => $reason]),
                null => $this->apply($item->product_size_id, $this->defaultWarehouseId(), ['on_hand' => $item->quantity],
                    StockMovement::TYPE_CANCELLATION, $context + ['reference' => $item, 'reason' => $reason], "cancellation:{$item->id}"),
                OrderItem::STOCK_FULFILLED => $this->apply($item->product_size_id, $this->fulfilledFrom($item), ['quarantined' => $item->quantity],
                    StockMovement::TYPE_CANCELLATION_RETURN, $context + ['reference' => $item, 'reason' => $reason.' after dispatch'], "cancellation-return:{$item->id}"),
                default => null,
            };

            $next = match ($item->stock_state) {
                OrderItem::STOCK_RESERVED => OrderItem::STOCK_RELEASED,
                null => OrderItem::STOCK_RESTOCKED,
                OrderItem::STOCK_FULFILLED => OrderItem::STOCK_RETURNED,
                default => $item->stock_state,
            };
            $item->forceFill(['stock_state' => $next])->save();
        });
    }

    /** A shipment came back undelivered: the dispatched units return to quarantine. */
    public function returnUndelivered(OrderItem $item, array $context = []): void
    {
        DB::transaction(function () use ($item, $context) {
            ProductSize::lockForUpdate()->find($item->product_size_id);
            $item = OrderItem::lockForUpdate()->findOrFail($item->id);

            if ($item->stock_state !== OrderItem::STOCK_FULFILLED) {
                return;
            }

            $this->apply($item->product_size_id, $this->fulfilledFrom($item), ['quarantined' => $item->quantity],
                StockMovement::TYPE_SHIPMENT_RETURN, $context + ['reference' => $item], "shipment-return:{$item->id}");
            $item->forceFill(['stock_state' => OrderItem::STOCK_RETURNED])->save();
        });
    }

    /** Units a customer returned (accepted return request) go to quarantine, never on sale. */
    public function receiveCustomerReturn(Model $returnItem, OrderItem $orderItem, int $quantity, array $context = []): void
    {
        $this->apply($orderItem->product_size_id, $this->fulfilledFrom($orderItem), ['quarantined' => $quantity],
            StockMovement::TYPE_RETURN_QUARANTINE, $context + ['reference' => $returnItem],
            'customer-return:'.$returnItem->getMorphClass().':'.$returnItem->getKey());
    }

    // ------------------------------------------------------------------ warehouse operations

    /** Manual correction of on-hand units (signed). A reason is mandatory. */
    public function adjust(int $sizeId, int $warehouseId, int $change, string $reason, ?int $adminId, ?string $notes = null): Collection
    {
        $this->requireReason($reason);

        return $this->apply($sizeId, $warehouseId, ['on_hand' => $change], StockMovement::TYPE_ADJUSTMENT,
            ['admin_id' => $adminId, 'reason' => $reason, 'notes' => $notes], 'adjustment:'.Str::uuid());
    }

    /** Sellable units found damaged: on_hand -> damaged. */
    public function markDamaged(int $sizeId, int $warehouseId, int $quantity, string $reason, ?int $adminId): Collection
    {
        $this->requirePositive($quantity);
        $this->requireReason($reason);

        return $this->apply($sizeId, $warehouseId, ['on_hand' => -$quantity, 'damaged' => $quantity], StockMovement::TYPE_DAMAGE,
            ['admin_id' => $adminId, 'reason' => $reason], 'damage:'.Str::uuid());
    }

    /** Damaged units destroyed or disposed of. */
    public function writeOffDamaged(int $sizeId, int $warehouseId, int $quantity, string $reason, ?int $adminId): Collection
    {
        $this->requirePositive($quantity);
        $this->requireReason($reason);

        return $this->apply($sizeId, $warehouseId, ['damaged' => -$quantity], StockMovement::TYPE_WRITE_OFF,
            ['admin_id' => $adminId, 'reason' => $reason], 'write-off:'.Str::uuid());
    }

    /**
     * Inspection of quarantined (returned) units. Only here can returned units become sellable.
     */
    public function inspectQuarantine(int $sizeId, int $warehouseId, int $restock, int $damaged, int $disposed, string $reason, ?int $adminId): void
    {
        if ($restock < 0 || $damaged < 0 || $disposed < 0 || $restock + $damaged + $disposed === 0) {
            throw new DomainException('Enter how many inspected units go back on sale, to damaged or are disposed of.');
        }
        $this->requireReason($reason);

        DB::transaction(function () use ($sizeId, $warehouseId, $restock, $damaged, $disposed, $reason, $adminId) {
            $key = 'inspection:'.Str::uuid();
            $context = ['admin_id' => $adminId, 'reason' => $reason];

            if ($restock) {
                $this->apply($sizeId, $warehouseId, ['quarantined' => -$restock, 'on_hand' => $restock], StockMovement::TYPE_RETURN_RESTOCK, $context, "{$key}:restock");
            }
            if ($damaged) {
                $this->apply($sizeId, $warehouseId, ['quarantined' => -$damaged, 'damaged' => $damaged], StockMovement::TYPE_RETURN_DAMAGED, $context, "{$key}:damaged");
            }
            if ($disposed) {
                $this->apply($sizeId, $warehouseId, ['quarantined' => -$disposed], StockMovement::TYPE_WRITE_OFF, $context, "{$key}:disposed");
            }
        });
    }

    // ------------------------------------------------------------------ helpers

    public function defaultWarehouseId(): int
    {
        $warehouse = Warehouse::default();

        if (!$warehouse) {
            throw new DomainException('No active warehouse is configured.');
        }

        return $warehouse->id;
    }

    /** Recalculate the catalogue projections of one SKU from its balances. */
    public function refreshProjection(ProductSize $size): void
    {
        $sellable = (int) InventoryBalance::where('product_size_id', $size->id)
            ->whereIn('warehouse_id', Warehouse::sellable()->select('id'))
            ->sum(DB::raw('on_hand - reserved'));
        $quarantined = (int) InventoryBalance::where('product_size_id', $size->id)->sum('quarantined');

        ProductSize::whereKey($size->id)->update(['stock' => $sellable, 'quarantined_stock' => $quarantined]);
        $size->stock = $sellable;
        $size->quarantined_stock = $quarantined;
    }

    private function lockedBalance(int $warehouseId, int $sizeId): InventoryBalance
    {
        $balance = InventoryBalance::where('warehouse_id', $warehouseId)->where('product_size_id', $sizeId)->lockForUpdate()->first();

        if (!$balance) {
            // The SKU row is locked by the caller, so no other request can create it meanwhile.
            InventoryBalance::create(['warehouse_id' => $warehouseId, 'product_size_id' => $sizeId]);
            $balance = InventoryBalance::where('warehouse_id', $warehouseId)->where('product_size_id', $sizeId)->lockForUpdate()->first();
        }

        return $balance;
    }

    private function activeReservations(OrderItem $item): Collection
    {
        return StockReservation::where('order_item_id', $item->id)->where('status', StockReservation::ACTIVE)->lockForUpdate()->get();
    }

    private function releaseReservations(OrderItem $item, array $context): void
    {
        foreach ($this->activeReservations($item) as $reservation) {
            $this->apply($item->product_size_id, $reservation->warehouse_id, ['reserved' => -$reservation->quantity],
                StockMovement::TYPE_RESERVATION_RELEASE, $context + ['reference' => $reservation], "release:{$reservation->id}");
            $reservation->update(['status' => StockReservation::RELEASED, 'released_at' => now()]);
        }
    }

    /** Where a line's units left from: its (first) fulfilled reservation, else the default warehouse. */
    private function fulfilledFrom(OrderItem $item): int
    {
        return (int) (StockReservation::where('order_item_id', $item->id)->where('status', StockReservation::FULFILLED)->orderBy('id')->value('warehouse_id')
            ?? $this->defaultWarehouseId());
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('A reason is required for every manual stock change.');
        }
    }

    private function requirePositive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new DomainException('The quantity must be at least 1.');
        }
    }
}
