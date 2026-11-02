<?php

namespace App\Modules\Inventory\Services;

use App\Models\InventoryBatch;
use App\Models\StockReservation;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Checks the inventory invariants (read-only):
 *  1. every balance bucket equals the sum of its ledger rows (warehouse rows only);
 *  2. reserved equals the active reservations of that SKU in that warehouse;
 *  3. no bucket is negative and reserved <= on_hand;
 *  4. product_sizes.stock / quarantined_stock equal the balances they project (stock: unreserved
 *     units of sellable batches in sellable warehouses);
 *  5. the batch balances of every warehouse, SKU and bucket add up to its balance, none negative.
 */
class InventoryIntegrity
{
    /** @return list<string> human-readable problems; empty when everything agrees */
    public function problems(): array
    {
        $problems = [];

        $ledger = DB::table('stock_movements')->whereNotNull('warehouse_id')
            ->groupBy('warehouse_id', 'product_size_id', 'bucket')
            ->selectRaw('warehouse_id, product_size_id, bucket, SUM(quantity_change) as total')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->warehouse_id}:{$row->product_size_id}:{$row->bucket}" => (int) $row->total]);

        $reserved = DB::table('stock_reservations')->where('status', StockReservation::ACTIVE)
            ->groupBy('warehouse_id', 'product_size_id')
            ->selectRaw('warehouse_id, product_size_id, SUM(quantity) as total')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->warehouse_id}:{$row->product_size_id}" => (int) $row->total]);

        foreach (DB::table('inventory_balances')->orderBy('id')->cursor() as $balance) {
            $key = "{$balance->warehouse_id}:{$balance->product_size_id}";

            foreach (['on_hand', 'reserved', 'damaged', 'quarantined', 'in_transit'] as $bucket) {
                $expected = $ledger["{$key}:{$bucket}"] ?? 0;
                if ((int) $balance->{$bucket} !== $expected) {
                    $problems[] = "SKU {$balance->product_size_id} in warehouse {$balance->warehouse_id}: {$bucket} is {$balance->{$bucket}}, ledger says {$expected}.";
                }
            }

            if ((int) $balance->reserved !== ($reserved[$key] ?? 0)) {
                $problems[] = "SKU {$balance->product_size_id} in warehouse {$balance->warehouse_id}: reserved is {$balance->reserved}, active reservations total ".($reserved[$key] ?? 0).'.';
            }

            if ((int) $balance->reserved > (int) $balance->on_hand) {
                $problems[] = "SKU {$balance->product_size_id} in warehouse {$balance->warehouse_id}: more reserved than on hand.";
            }
        }

        $batchTotals = DB::table('batch_balances')->groupBy('warehouse_id', 'product_size_id')
            ->selectRaw('warehouse_id, product_size_id, SUM(on_hand) as on_hand, SUM(reserved) as reserved, SUM(damaged) as damaged, SUM(quarantined) as quarantined, SUM(in_transit) as in_transit')
            ->get()->keyBy(fn ($row) => "{$row->warehouse_id}:{$row->product_size_id}");

        foreach (DB::table('inventory_balances')->orderBy('id')->cursor() as $balance) {
            $key = "{$balance->warehouse_id}:{$balance->product_size_id}";
            $batches = $batchTotals[$key] ?? null;
            foreach (['on_hand', 'reserved', 'damaged', 'quarantined', 'in_transit'] as $bucket) {
                if ((int) $balance->{$bucket} !== (int) ($batches->{$bucket} ?? 0)) {
                    $problems[] = "SKU {$balance->product_size_id} in warehouse {$balance->warehouse_id}: {$bucket} is {$balance->{$bucket}}, its batches total ".(int) ($batches->{$bucket} ?? 0).'.';
                }
            }
        }

        if ($bad = DB::table('batch_balances')->where(fn ($q) => $q->where('on_hand', '<', 0)->orWhere('reserved', '<', 0)->orWhere('damaged', '<', 0)
            ->orWhere('quarantined', '<', 0)->orWhere('in_transit', '<', 0)->orWhereColumn('reserved', '>', 'on_hand'))->count()) {
            $problems[] = "{$bad} batch balance(s) are negative or reserve more than they hold.";
        }

        $sellableWarehouses = Warehouse::sellable()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sellableBatches = InventoryBatch::sellable()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sellableBySize = DB::table('batch_balances')->whereIn('warehouse_id', $sellableWarehouses ?: [0])->whereIn('inventory_batch_id', $sellableBatches ?: [0])
            ->groupBy('product_size_id')->selectRaw('product_size_id, SUM(on_hand - reserved) as sellable')->pluck('sellable', 'product_size_id');
        $quarantinedBySize = DB::table('inventory_balances')->groupBy('product_size_id')->selectRaw('product_size_id, SUM(quarantined) as quarantined')->pluck('quarantined', 'product_size_id');

        foreach (DB::table('product_sizes')->orderBy('id')->get(['id', 'stock', 'quarantined_stock']) as $row) {
            $sellable = (int) ($sellableBySize[$row->id] ?? 0);
            $quarantined = (int) ($quarantinedBySize[$row->id] ?? 0);
            if ((int) $row->stock !== $sellable) {
                $problems[] = "SKU {$row->id}: product_sizes.stock is {$row->stock}, sellable batches total {$sellable}.";
            }
            if ((int) $row->quarantined_stock !== $quarantined) {
                $problems[] = "SKU {$row->id}: quarantined_stock is {$row->quarantined_stock}, balances total {$quarantined}.";
            }
        }

        return $problems;
    }
}
