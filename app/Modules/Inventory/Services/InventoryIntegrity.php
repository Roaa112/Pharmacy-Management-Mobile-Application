<?php

namespace App\Modules\Inventory\Services;

use App\Models\StockReservation;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Checks the inventory invariants (read-only):
 *  1. every balance bucket equals the sum of its ledger rows (warehouse rows only);
 *  2. reserved equals the active reservations of that SKU in that warehouse;
 *  3. no bucket is negative and reserved <= on_hand;
 *  4. product_sizes.stock / quarantined_stock equal the balances they project.
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

        $sellable = Warehouse::sellable()->pluck('id');
        $projections = DB::table('product_sizes')
            ->leftJoin('inventory_balances', 'inventory_balances.product_size_id', '=', 'product_sizes.id')
            ->groupBy('product_sizes.id', 'product_sizes.stock', 'product_sizes.quarantined_stock')
            ->selectRaw('product_sizes.id, product_sizes.stock, product_sizes.quarantined_stock, '
                .'COALESCE(SUM(CASE WHEN inventory_balances.warehouse_id IN ('.($sellable->implode(',') ?: '0').') THEN inventory_balances.on_hand - inventory_balances.reserved ELSE 0 END), 0) as sellable, '
                .'COALESCE(SUM(inventory_balances.quarantined), 0) as quarantined')
            ->get();

        foreach ($projections as $row) {
            if ((int) $row->stock !== (int) $row->sellable) {
                $problems[] = "SKU {$row->id}: product_sizes.stock is {$row->stock}, sellable balances total {$row->sellable}.";
            }
            if ((int) $row->quarantined_stock !== (int) $row->quarantined) {
                $problems[] = "SKU {$row->id}: quarantined_stock is {$row->quarantined_stock}, balances total {$row->quarantined}.";
            }
        }

        return $problems;
    }
}
