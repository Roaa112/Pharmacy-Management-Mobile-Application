<?php

namespace App\Modules\Inventory\Services;

use App\Models\BatchBalance;
use App\Models\InventoryBalance;
use App\Models\InventoryBatch;
use App\Models\OrderItem;
use App\Models\ProductSize;
use App\Models\ReturnRequestItem;
use App\Models\StockMovement;
use App\Models\StockMovementBatch;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Support\StoreClock;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only code that changes stock. Every change:
 *  - runs in a transaction and locks the SKU row (product_sizes) first, so all changes to one
 *    SKU are serialized, whichever warehouse they touch (one lock order: no deadlocks);
 *  - writes one immutable stock_movements row per bucket it changes, with before/after, and the
 *    batches it changed (stock_movement_batches);
 *  - carries an idempotency key per bucket (unique in the database): repeating the same
 *    business event changes nothing;
 *  - refuses any bucket below zero and reserved above on_hand, for the SKU and for every batch
 *    (no negative stock, no overselling);
 *  - refreshes the product_sizes.stock / quarantined_stock projections the catalogue reads.
 *
 * Buckets: on_hand (sellable condition, physically present), reserved (part of on_hand held
 * for orders), damaged, quarantined (customer returns awaiting inspection), in_transit.
 *
 * Batches (lots with an expiry date) split every bucket: batch_balances add up to
 * inventory_balances. A change names its batches (context "batches", or "batch_id" for units
 * coming in) or they are chosen here: units leaving a bucket go first-expiry-first-out; units
 * moving between buckets keep their batch; units arriving without a batch go to the SKU's legacy
 * batch. Only sellable batches (not expired, not inside the minimum shelf life) count as stock
 * the catalogue can sell, are reserved by checkout, and can be dispatched.
 */
class InventoryService
{
    /**
     * Apply bucket changes to one SKU in one warehouse.
     *
     * @param  array<string, int>  $deltas  bucket => signed change
     * @param  array{reference?: Model, user_id?: int, admin_id?: int, reason?: string, notes?: string, unit_cost?: mixed,
     *               batch_id?: int, batches?: array<string, array<int, int>>, sellable_only?: bool}  $context  batches: bucket => [batch id => signed change]
     * @return Collection<int, StockMovement> the rows written (empty when the key was already applied)
     */
    public function apply(int $sizeId, int $warehouseId, array $deltas, string $type, array $context, string $key): Collection
    {
        $explicit = array_filter($context['batches'] ?? [], fn ($lines) => $lines !== []);
        // A bucket whose total does not change can still move units between batches (reallocation).
        $deltas = array_filter($deltas, fn ($change, $bucket) => $change !== 0 || isset($explicit[$bucket]), ARRAY_FILTER_USE_BOTH);

        foreach (array_keys($deltas) as $bucket) {
            if (!in_array($bucket, InventoryBalance::BUCKETS, true)) {
                throw new DomainException("Unknown stock bucket {$bucket}.");
            }
        }

        if (!$deltas) {
            return collect();
        }

        return DB::transaction(function () use ($sizeId, $warehouseId, $deltas, $type, $context, $key, $explicit) {
            $size = ProductSize::lockForUpdate()->findOrFail($sizeId);

            $keys = array_map(fn ($bucket) => "{$key}:{$bucket}", array_keys($deltas));
            // A plain read on purpose: a locking read here would take gap locks on the unique key index
            // that checkouts of other SKUs (adjacent keys) would deadlock on. A movement this read misses
            // (committed after the snapshot) is still rejected by the unique index: never applied twice.
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

            $allocation = $this->allocate($size, $warehouseId, $deltas, $explicit, $context);
            $this->applyToBatches($size, $warehouseId, $allocation);

            InventoryBalance::whereKey($balance->id)->update($after + ['updated_at' => now()]);

            $reference = $context['reference'] ?? null;
            $movements = collect();

            foreach ($deltas as $bucket => $change) {
                $movement = StockMovement::create([
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
                ]);

                $lines = collect($allocation[$bucket] ?? [])->filter()->map(fn ($qty, $batchId) => StockMovementBatch::create([
                    'stock_movement_id' => $movement->id, 'inventory_batch_id' => $batchId, 'quantity_change' => $qty,
                ]))->values();
                $movements->push($movement->setRelation('batchLines', $lines));
            }

            $this->refreshProjection($size);

            return $movements;
        });
    }

    /**
     * Units that can be sold now from sellable warehouses, per warehouse (default first): sellable
     * batches only. Callers hold the SKU lock; rows are read with locking reads, so they are the
     * committed values, not the transaction's REPEATABLE READ snapshot (which can predate the lock).
     */
    public function availability(int $sizeId): Collection
    {
        $warehouses = Warehouse::sellable()->get(['id', 'is_default'])->keyBy('id');
        $batchIds = InventoryBatch::where('product_size_id', $sizeId)->sellable()->lockForUpdate()->pluck('id');

        return BatchBalance::where('product_size_id', $sizeId)->whereIn('warehouse_id', $warehouses->keys())->whereIn('inventory_batch_id', $batchIds)
            ->lockForUpdate()->get(['warehouse_id', 'on_hand', 'reserved'])
            ->groupBy('warehouse_id')
            ->map(fn ($rows, $warehouseId) => ['warehouse_id' => (int) $warehouseId, 'available' => (int) $rows->sum(fn ($b) => $b->on_hand - $b->reserved), 'default' => (bool) $warehouses[$warehouseId]->is_default])
            ->filter(fn ($row) => $row['available'] > 0)
            ->sort(fn ($a, $b) => [$b['default'], $b['available'], $a['warehouse_id']] <=> [$a['default'], $a['available'], $b['warehouse_id']])
            ->mapWithKeys(fn ($row) => [$row['warehouse_id'] => $row['available']]);
    }

    // ------------------------------------------------------------------ orders

    /**
     * Hold units of an order line: default sellable warehouse first, then the warehouse with
     * the most available units; inside a warehouse, the sellable batch expiring first (FEFO).
     * One reservation per warehouse and batch. Throws InsufficientStock if all sellable
     * warehouses together do not have enough (the caller's transaction then rolls back).
     */
    public function reserve(OrderItem $item, array $context = []): void
    {
        DB::transaction(function () use ($item, $context) {
            $size = ProductSize::lockForUpdate()->findOrFail($item->product_size_id);
            $remaining = (int) $item->quantity;

            foreach ($this->availability($item->product_size_id) as $warehouseId => $available) {
                if ($remaining <= 0) {
                    break;
                }

                foreach ($this->fefo($size, $warehouseId, 'on_hand', min($remaining, $available), availableOnly: true, sellableOnly: true) as $batchId => $taken) {
                    $reservation = StockReservation::create([
                        'order_item_id' => $item->id, 'product_size_id' => $item->product_size_id, 'warehouse_id' => $warehouseId,
                        'inventory_batch_id' => $batchId, 'quantity' => -$taken, 'status' => StockReservation::ACTIVE,
                    ]);

                    $this->apply($item->product_size_id, $warehouseId, ['reserved' => -$taken], StockMovement::TYPE_RESERVATION,
                        $context + ['reference' => $reservation, 'reason' => $context['reason'] ?? "Order #{$item->order_id}", 'batches' => ['reserved' => [$batchId => -$taken]]],
                        "reservation:{$reservation->id}");

                    $remaining += $taken;
                }
            }

            if ($remaining > 0) {
                throw new InsufficientStock(ProductSize::find($item->product_size_id), null, 'available', (int) $item->quantity - $remaining, (int) $item->quantity);
            }

            $item->forceFill(['stock_state' => OrderItem::STOCK_RESERVED])->save();
        });
    }

    /**
     * The reserved units leave the warehouse (dispatch, or hand-over for pickup orders). A
     * reservation in a batch that expired meanwhile is first moved to an unexpired batch of the
     * same warehouse; expired medicine is never dispatched (DomainException when none is left).
     */
    public function fulfil(OrderItem $item, array $context = []): void
    {
        DB::transaction(function () use ($item, $context) {
            ProductSize::lockForUpdate()->find($item->product_size_id);
            $item = OrderItem::lockForUpdate()->findOrFail($item->id);

            if ($item->stock_state !== OrderItem::STOCK_RESERVED) {
                return; // legacy lines left stock at checkout; fulfilled lines are done
            }

            $this->replaceExpiredReservations($item, $context);

            $type = $item->is_gift ? StockMovement::TYPE_GIFT : StockMovement::TYPE_FULFILLMENT;

            foreach ($this->activeReservations($item) as $reservation) {
                $batches = $reservation->inventory_batch_id ? [$reservation->inventory_batch_id => -$reservation->quantity] : [];
                $this->apply($item->product_size_id, $reservation->warehouse_id,
                    ['on_hand' => -$reservation->quantity, 'reserved' => -$reservation->quantity], $type,
                    $context + ['reference' => $reservation, 'reason' => $context['reason'] ?? "Order #{$item->order_id} dispatched",
                        'batches' => array_filter(['on_hand' => $batches, 'reserved' => $batches])],
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
     *  - fulfilled: the units left the warehouse; they come back to quarantine (in their batches) for inspection;
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
                OrderItem::STOCK_FULFILLED => $this->backToQuarantine($item, (int) $item->quantity, 0, StockMovement::TYPE_CANCELLATION_RETURN,
                    $context + ['reference' => $item, 'reason' => $reason.' after dispatch'], "cancellation-return:{$item->id}"),
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

            $this->backToQuarantine($item, (int) $item->quantity, 0, StockMovement::TYPE_SHIPMENT_RETURN, $context + ['reference' => $item], "shipment-return:{$item->id}");
            $item->forceFill(['stock_state' => OrderItem::STOCK_RETURNED])->save();
        });
    }

    /** Units a customer returned (accepted return request) go to quarantine, never on sale, in the batches they were sold from. */
    public function receiveCustomerReturn(Model $returnItem, OrderItem $orderItem, int $quantity, array $context = []): void
    {
        DB::transaction(function () use ($returnItem, $orderItem, $quantity, $context) {
            ProductSize::lockForUpdate()->find($orderItem->product_size_id);

            $alreadyReturned = (int) ReturnRequestItem::where('order_item_id', $orderItem->id)->whereKeyNot($returnItem->getKey())
                ->whereHas('returnRequest', fn ($q) => $q->where('status', 'accepted'))->sum('quantity');

            $this->backToQuarantine($orderItem, $quantity, $alreadyReturned, StockMovement::TYPE_RETURN_QUARANTINE, $context + ['reference' => $returnItem],
                'customer-return:'.$returnItem->getMorphClass().':'.$returnItem->getKey());
        });
    }

    // ------------------------------------------------------------------ warehouse operations

    /** Manual correction of on-hand units (signed). A reason is mandatory. Units added go to $batchId (else the legacy batch); units removed come from $batchId (else FEFO). */
    public function adjust(int $sizeId, int $warehouseId, int $change, string $reason, ?int $adminId, ?string $notes = null, ?int $batchId = null): Collection
    {
        $this->requireReason($reason);

        return $this->apply($sizeId, $warehouseId, ['on_hand' => $change], StockMovement::TYPE_ADJUSTMENT,
            ['admin_id' => $adminId, 'reason' => $reason, 'notes' => $notes] + $this->batchContext($sizeId, $batchId, ['on_hand' => $change]), 'adjustment:'.Str::uuid());
    }

    /** Sellable units found damaged: on_hand -> damaged. */
    public function markDamaged(int $sizeId, int $warehouseId, int $quantity, string $reason, ?int $adminId, ?int $batchId = null): Collection
    {
        $this->requirePositive($quantity);
        $this->requireReason($reason);

        return $this->apply($sizeId, $warehouseId, ['on_hand' => -$quantity, 'damaged' => $quantity], StockMovement::TYPE_DAMAGE,
            ['admin_id' => $adminId, 'reason' => $reason] + $this->batchContext($sizeId, $batchId, ['on_hand' => -$quantity, 'damaged' => $quantity]), 'damage:'.Str::uuid());
    }

    /** Damaged units destroyed or disposed of. */
    public function writeOffDamaged(int $sizeId, int $warehouseId, int $quantity, string $reason, ?int $adminId, ?int $batchId = null): Collection
    {
        $this->requirePositive($quantity);
        $this->requireReason($reason);

        return $this->apply($sizeId, $warehouseId, ['damaged' => -$quantity], StockMovement::TYPE_WRITE_OFF,
            ['admin_id' => $adminId, 'reason' => $reason] + $this->batchContext($sizeId, $batchId, ['damaged' => -$quantity]), 'write-off:'.Str::uuid());
    }

    /**
     * Inspection of quarantined (returned) units. Only here can returned units become sellable
     * (and only if their batch is: an expired batch's units go back on hand but are never sold).
     */
    public function inspectQuarantine(int $sizeId, int $warehouseId, int $restock, int $damaged, int $disposed, string $reason, ?int $adminId, ?int $batchId = null): void
    {
        if ($restock < 0 || $damaged < 0 || $disposed < 0 || $restock + $damaged + $disposed === 0) {
            throw new DomainException('Enter how many inspected units go back on sale, to damaged or are disposed of.');
        }
        $this->requireReason($reason);

        DB::transaction(function () use ($sizeId, $warehouseId, $restock, $damaged, $disposed, $reason, $adminId, $batchId) {
            $key = 'inspection:'.Str::uuid();
            $context = ['admin_id' => $adminId, 'reason' => $reason];

            if ($restock) {
                $this->apply($sizeId, $warehouseId, ['quarantined' => -$restock, 'on_hand' => $restock], StockMovement::TYPE_RETURN_RESTOCK,
                    $context + $this->batchContext($sizeId, $batchId, ['quarantined' => -$restock, 'on_hand' => $restock]), "{$key}:restock");
            }
            if ($damaged) {
                $this->apply($sizeId, $warehouseId, ['quarantined' => -$damaged, 'damaged' => $damaged], StockMovement::TYPE_RETURN_DAMAGED,
                    $context + $this->batchContext($sizeId, $batchId, ['quarantined' => -$damaged, 'damaged' => $damaged]), "{$key}:damaged");
            }
            if ($disposed) {
                $this->apply($sizeId, $warehouseId, ['quarantined' => -$disposed], StockMovement::TYPE_WRITE_OFF,
                    $context + $this->batchContext($sizeId, $batchId, ['quarantined' => -$disposed]), "{$key}:disposed");
            }
        });
    }

    /**
     * Daily: unreserved units of expired batches leave sellable stock (on_hand -> damaged, an
     * "expiry" movement), to be returned to the supplier or destroyed. They were already
     * excluded from the catalogue from the expiry date on; this records it in the ledger.
     *
     * @return int units moved
     */
    public function expireBatches(): int
    {
        $today = StoreClock::now()->toDateString();
        $moved = 0;

        BatchBalance::query()->join('inventory_batches', 'inventory_batches.id', '=', 'batch_balances.inventory_batch_id')
            ->whereNotNull('inventory_batches.expiry_date')->where('inventory_batches.expiry_date', '<=', $today)
            ->whereRaw('batch_balances.on_hand > batch_balances.reserved')
            ->orderBy('batch_balances.product_size_id')->orderBy('batch_balances.id')
            ->get(['batch_balances.id', 'batch_balances.product_size_id', 'batch_balances.warehouse_id', 'batch_balances.inventory_batch_id'])
            ->each(function ($row) use ($today, &$moved) {
                DB::transaction(function () use ($row, $today, &$moved) {
                    ProductSize::lockForUpdate()->find($row->product_size_id);
                    $balance = BatchBalance::lockForUpdate()->find($row->id);
                    $quantity = $balance->on_hand - $balance->reserved;
                    if ($quantity <= 0) {
                        return;
                    }

                    $batch = InventoryBatch::find($row->inventory_batch_id);
                    $this->apply($row->product_size_id, $row->warehouse_id, ['on_hand' => -$quantity, 'damaged' => $quantity], StockMovement::TYPE_EXPIRY,
                        ['reason' => "Batch {$batch->label()} expired", 'batches' => ['on_hand' => [$batch->id => -$quantity], 'damaged' => [$batch->id => $quantity]]],
                        "expiry:{$batch->id}:{$row->warehouse_id}:{$today}");
                    $moved += $quantity;
                });
            });

        // Batches that entered the minimum shelf life today are not expired (nothing moves) but
        // are no longer for sale: refresh the catalogue stock of their SKUs.
        BatchBalance::query()->join('inventory_batches', 'inventory_batches.id', '=', 'batch_balances.inventory_batch_id')
            ->whereNotNull('inventory_batches.expiry_date')->where('inventory_batches.expiry_date', '<=', InventoryBatch::sellableCutoff())
            ->whereRaw('batch_balances.on_hand > batch_balances.reserved')
            ->distinct()->orderBy('batch_balances.product_size_id')->pluck('batch_balances.product_size_id')
            ->each(fn ($sizeId) => DB::transaction(fn () => $this->refreshProjection(ProductSize::lockForUpdate()->findOrFail($sizeId))));

        return $moved;
    }

    // ------------------------------------------------------------------ batches

    /**
     * The lot received (found or created, under the SKU lock). Received without lot or expiry:
     * a batch of its own (never the legacy batch, which means "from before tracking"), so its
     * receipt date is kept and it is sold after dated lots.
     */
    public function batchFor(int $sizeId, ?string $lotNumber, ?string $expiryDate, array $attributes = []): InventoryBatch
    {
        $lotNumber = filled($lotNumber) ? trim($lotNumber) : null;

        return DB::transaction(function () use ($sizeId, $lotNumber, $expiryDate, $attributes) {
            ProductSize::lockForUpdate()->findOrFail($sizeId);

            if ($lotNumber === null && $expiryDate === null) {
                return InventoryBatch::create(['product_size_id' => $sizeId, 'notes' => 'Received without lot or expiry'] + $attributes);
            }

            return InventoryBatch::where('product_size_id', $sizeId)->where('is_legacy', false)
                ->where(fn ($q) => $lotNumber === null ? $q->whereNull('lot_number') : $q->where('lot_number', $lotNumber))
                ->where(fn ($q) => $expiryDate === null ? $q->whereNull('expiry_date') : $q->whereDate('expiry_date', $expiryDate))
                ->lockForUpdate()->first()
                ?? InventoryBatch::create(['product_size_id' => $sizeId, 'lot_number' => $lotNumber, 'expiry_date' => $expiryDate] + $attributes);
        });
    }

    /** The SKU's batch for units of unknown lot and expiry. */
    public function legacyBatch(int $sizeId): InventoryBatch
    {
        return DB::transaction(function () use ($sizeId) {
            ProductSize::lockForUpdate()->findOrFail($sizeId);

            return InventoryBatch::where('product_size_id', $sizeId)->where('is_legacy', true)->lockForUpdate()->first()
                ?? InventoryBatch::create(['product_size_id' => $sizeId, 'is_legacy' => true, 'notes' => 'Units of unknown lot and expiry']);
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

    /**
     * Recalculate the catalogue projections of one SKU: stock = unreserved units of sellable
     * batches in sellable warehouses; quarantined = all quarantined units. Locking reads (the SKU
     * is locked by the caller): committed balances, not a stale snapshot.
     */
    public function refreshProjection(ProductSize $size): void
    {
        $sellableWarehouses = Warehouse::sellable()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sellableBatches = InventoryBatch::where('product_size_id', $size->id)->sellable()->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $sellable = (int) BatchBalance::where('product_size_id', $size->id)->whereIn('warehouse_id', $sellableWarehouses)->whereIn('inventory_batch_id', $sellableBatches)
            ->lockForUpdate()->get(['on_hand', 'reserved'])->sum(fn ($b) => $b->on_hand - $b->reserved);
        $quarantined = (int) InventoryBalance::where('product_size_id', $size->id)->lockForUpdate()->sum('quarantined');

        ProductSize::whereKey($size->id)->update(['stock' => $sellable, 'quarantined_stock' => $quarantined]);
        $size->stock = $sellable;
        $size->quarantined_stock = $quarantined;
    }

    /**
     * The batch changes of one apply(): bucket => [batch id => signed change].
     *  1. batches named by the caller;
     *  2. units leaving a bucket: FEFO (on_hand counts only unreserved units, unless reserved units leave too;
     *     with context sellable_only, only sellable batches: a change to the catalogue stock);
     *  3. units arriving: the batches of the units that left another bucket in the same change
     *     (a move between buckets keeps the batch), reserved units FEFO over sellable batches, or
     *     context batch_id / the legacy batch.
     */
    private function allocate(ProductSize $size, int $warehouseId, array $deltas, array $explicit, array $context): array
    {
        $result = [];

        foreach ($deltas as $bucket => $change) {
            if (isset($explicit[$bucket])) {
                if (array_sum($explicit[$bucket]) !== $change) {
                    throw new DomainException("The batch quantities of {$bucket} do not add up to the change.");
                }
                $result[$bucket] = $explicit[$bucket];
            }
        }

        foreach ($deltas as $bucket => $change) {
            if (!isset($result[$bucket]) && $change < 0) {
                $reservedLeaves = ($deltas['reserved'] ?? 0) < 0;
                $result[$bucket] = $bucket === 'on_hand' && $reservedLeaves && isset($result['reserved'])
                    ? $result['reserved']
                    : $this->fefo($size, $warehouseId, $bucket === 'on_hand' && $reservedLeaves ? 'reserved' : $bucket, -$change,
                        availableOnly: $bucket === 'on_hand' && !$reservedLeaves,
                        sellableOnly: $bucket === 'on_hand' && !$reservedLeaves && !empty($context['sellable_only']));
            }
        }

        foreach ($deltas as $bucket => $change) {
            if (isset($result[$bucket]) || $change <= 0) {
                continue;
            }

            $mirror = collect($result)->first(fn ($lines, $other) => $other !== $bucket && $deltas[$other] === -$change);

            $result[$bucket] = match (true) {
                $mirror !== null => array_map(fn ($qty) => -$qty, $mirror),
                $bucket === 'reserved' => array_map(fn ($qty) => -$qty, $this->fefo($size, $warehouseId, 'on_hand', $change, availableOnly: true, sellableOnly: true)),
                default => [(int) ($context['batch_id'] ?? $this->legacyBatch($size->id)->id) => $change],
            };
        }

        return $result;
    }

    /**
     * Take $quantity units of $bucket in this warehouse, earliest expiry first.
     *
     * @return array<int, int> batch id => negative quantity taken
     */
    private function fefo(ProductSize $size, int $warehouseId, string $bucket, int $quantity, bool $availableOnly = false, bool $sellableOnly = false): array
    {
        if ($quantity <= 0) {
            return [];
        }

        $batches = InventoryBatch::where('product_size_id', $size->id)->when($sellableOnly, fn ($q) => $q->sellable())->fefo()->lockForUpdate()->get(['id']);
        $balances = BatchBalance::where('warehouse_id', $warehouseId)->whereIn('inventory_batch_id', $batches->pluck('id'))->lockForUpdate()->get()->keyBy('inventory_batch_id');

        $taken = [];
        $left = $quantity;
        foreach ($batches as $batch) {
            $row = $balances->get($batch->id);
            $units = $row ? ($availableOnly ? $row->on_hand - $row->reserved : $row->{$bucket}) : 0;
            if ($units <= 0) {
                continue;
            }
            $take = min($left, $units);
            $taken[$batch->id] = -$take;
            $left -= $take;
            if ($left === 0) {
                break;
            }
        }

        if ($left > 0) {
            throw new InsufficientStock($size, $warehouseId, $sellableOnly ? 'unexpired available' : ($availableOnly ? 'available' : $bucket), $quantity - $left, $quantity);
        }

        return $taken;
    }

    /** Write the batch changes; every batch bucket stays >= 0 and reserved <= on_hand. */
    private function applyToBatches(ProductSize $size, int $warehouseId, array $allocation): void
    {
        $batchIds = collect($allocation)->flatMap(fn ($lines) => array_keys($lines))->unique()->sort()->values();
        if ($batchIds->isEmpty()) {
            return;
        }

        $rows = BatchBalance::where('warehouse_id', $warehouseId)->whereIn('inventory_batch_id', $batchIds)->lockForUpdate()->get()->keyBy('inventory_batch_id');

        foreach ($batchIds as $batchId) {
            // Locking reads only: under REPEATABLE READ a plain re-read (refresh()) returns the
            // transaction's snapshot, which can predate the SKU lock, and would overwrite changes
            // committed meanwhile (a lost update, found by tests/Feature/Concurrency).
            $row = $rows->get($batchId);
            if (!$row) {
                $created = BatchBalance::create(['warehouse_id' => $warehouseId, 'inventory_batch_id' => $batchId, 'product_size_id' => $size->id]);
                $row = BatchBalance::whereKey($created->id)->lockForUpdate()->firstOrFail();
            }
            $values = $row->only(InventoryBalance::BUCKETS);

            foreach ($allocation as $bucket => $lines) {
                $values[$bucket] += $lines[$batchId] ?? 0;
                if ($values[$bucket] < 0) {
                    throw new InsufficientStock($size, $warehouseId, "{$bucket} (batch #{$batchId})", $values[$bucket] - ($lines[$batchId] ?? 0), -($lines[$batchId] ?? 0));
                }
            }
            if ($values['reserved'] > $values['on_hand']) {
                throw new InsufficientStock($size, $warehouseId, "available (batch #{$batchId})", $row->on_hand - $row->reserved, $values['reserved'] - $row->reserved);
            }

            BatchBalance::whereKey($row->id)->update($values + ['updated_at' => now()]);
        }
    }

    /** An optional batch picked by an admin, as explicit allocations for every bucket of the change. */
    private function batchContext(int $sizeId, ?int $batchId, array $deltas): array
    {
        if (!$batchId) {
            return [];
        }

        if (!InventoryBatch::whereKey($batchId)->where('product_size_id', $sizeId)->exists()) {
            throw new DomainException('That batch belongs to another product.');
        }

        return ['batch_id' => $batchId, 'batches' => array_map(fn ($change) => [$batchId => $change], array_filter($deltas))];
    }

    /** Dispatching never ships an expired batch: such a reservation moves to unexpired batches of its warehouse first. */
    private function replaceExpiredReservations(OrderItem $item, array $context): void
    {
        foreach ($this->activeReservations($item) as $reservation) {
            $batch = $reservation->inventory_batch_id ? InventoryBatch::find($reservation->inventory_batch_id) : null;
            if (!$batch?->isExpired()) {
                continue;
            }

            $size = ProductSize::findOrFail($item->product_size_id);
            try {
                $replacement = $this->fefo($size, $reservation->warehouse_id, 'on_hand', $reservation->quantity, availableOnly: true, sellableOnly: true);
            } catch (InsufficientStock) {
                throw new DomainException("Batch {$batch->label()} of {$size->product?->name_en} {$size->size} expired and the warehouse has no unexpired units to replace it; restock or cancel the order.");
            }

            $lines = [$batch->id => -$reservation->quantity] + array_map(fn ($qty) => -$qty, $replacement);
            $this->apply($item->product_size_id, $reservation->warehouse_id, ['reserved' => 0], StockMovement::TYPE_BATCH_REALLOCATION,
                $context + ['reference' => $reservation, 'reason' => "Batch {$batch->label()} expired before dispatch", 'batches' => ['reserved' => $lines]],
                "reallocation:{$reservation->id}");

            $reservation->update(['status' => StockReservation::RELEASED, 'released_at' => now()]);
            foreach ($replacement as $batchId => $qty) {
                StockReservation::create([
                    'order_item_id' => $item->id, 'product_size_id' => $item->product_size_id, 'warehouse_id' => $reservation->warehouse_id,
                    'inventory_batch_id' => $batchId, 'quantity' => -$qty, 'status' => StockReservation::ACTIVE,
                ]);
            }
        }
    }

    /**
     * Put $quantity dispatched units of a line back into quarantine, in the warehouses and batches
     * they left from (fulfilled reservations in order, skipping $skip units already returned).
     */
    private function backToQuarantine(OrderItem $item, int $quantity, int $skip, string $type, array $context, string $key): void
    {
        $groups = [];
        $left = $quantity;

        foreach (StockReservation::where('order_item_id', $item->id)->where('status', StockReservation::FULFILLED)->orderBy('id')->lockForUpdate()->get() as $reservation) {
            $units = $reservation->quantity;
            $skipped = min($skip, $units);
            $skip -= $skipped;
            $take = min($left, $units - $skipped);
            if ($take <= 0) {
                continue;
            }
            $groups[$reservation->warehouse_id][(int) $reservation->inventory_batch_id] = ($groups[$reservation->warehouse_id][(int) $reservation->inventory_batch_id] ?? 0) + $take;
            $left -= $take;
            if ($left === 0) {
                break;
            }
        }

        if ($left > 0) {
            // Legacy lines (no reservations): the default warehouse's legacy batch.
            $default = $this->defaultWarehouseId();
            $groups[$default][0] = ($groups[$default][0] ?? 0) + $left;
        }

        $index = 0;
        foreach ($groups as $warehouseId => $batches) {
            $total = array_sum($batches);
            $explicit = array_filter($batches, fn ($qty, $batchId) => $batchId !== 0, ARRAY_FILTER_USE_BOTH);
            $batchesContext = count($explicit) === count($batches) ? ['batches' => ['quarantined' => $explicit]] : [];
            $this->apply($item->product_size_id, $warehouseId, ['quarantined' => $total], $type, $context + $batchesContext, $index++ === 0 ? $key : "{$key}:w{$warehouseId}");
        }
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
        return StockReservation::where('order_item_id', $item->id)->where('status', StockReservation::ACTIVE)->orderBy('id')->lockForUpdate()->get();
    }

    private function releaseReservations(OrderItem $item, array $context): void
    {
        foreach ($this->activeReservations($item) as $reservation) {
            $this->apply($item->product_size_id, $reservation->warehouse_id, ['reserved' => -$reservation->quantity],
                StockMovement::TYPE_RESERVATION_RELEASE, $context + ['reference' => $reservation]
                    + ($reservation->inventory_batch_id ? ['batches' => ['reserved' => [$reservation->inventory_batch_id => -$reservation->quantity]]] : []),
                "release:{$reservation->id}");
            $reservation->update(['status' => StockReservation::RELEASED, 'released_at' => now()]);
        }
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
