<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Runs the 2026_11_03 migrations on a database shaped like production before them (legacy delivery
 * prices, orders without snapshots, redeemed points) and checks the backfills.
 */
class MigrationBackfillTest extends TestCase
{
    /** The audit migrations start here; everything from this date on is "new". */
    private const NEW_MIGRATIONS = '2026_11_03_';

    public static function isNew(string $path): bool
    {
        return strcmp(basename($path), self::NEW_MIGRATIONS) >= 0;
    }

    protected function tearDown(): void
    {
        // Leave a clean, fully migrated database for the other tests.
        Artisan::call('migrate:fresh');
        parent::tearDown();
    }

    public function test_new_migrations_backfill_legacy_data_without_changing_money(): void
    {
        $old = collect(File::files(database_path('migrations')))
            ->map->getPathname()
            ->reject(fn ($path) => self::isNew($path))
            ->values()->all();
        Artisan::call('migrate:fresh', ['--path' => $old, '--realpath' => true]);

        // ---- legacy data
        $now = now();
        DB::table('settings')->insert(['spend_x' => '10', 'get_y' => '1', 'zaincash' => '01001234567', 'points_validity_days' => 30, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('cities')->insert(['name' => 'Cairo', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('delivery_prices')->insert([
            ['governorate' => 'Cairo', 'price' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['governorate' => 'الإسكندرية', 'price' => 7.5, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $userId = DB::table('users')->insertGetId(['name' => 'Old', 'email' => 'old@example.com', 'password' => Hash::make('x'), 'is_verified' => true, 'created_at' => $now, 'updated_at' => $now]);
        $addressId = DB::table('addresses')->insertGetId(['user_id' => $userId, 'governorate' => 'الإسكندرية', 'city' => 'Center', 'street' => 'Old street', 'type' => 'home', 'created_at' => $now, 'updated_at' => $now]);
        $productId = DB::table('products')->insertGetId(['name_ar' => 'م', 'name_en' => 'P', 'image' => 'p.png', 'created_at' => $now, 'updated_at' => $now]);
        $sizeId = DB::table('product_sizes')->insertGetId(['product_id' => $productId, 'size' => 'M', 'price' => 50, 'stock' => 5, 'created_at' => $now, 'updated_at' => $now]);
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $userId, 'address_id' => $addressId, 'payment_method' => 'zaincash', 'payment_image' => 'payments/r.jpg',
            'delivery_fee' => 7.5, 'total_price' => 97.5, 'points_earned' => 10, 'points_status' => 'redeemed', 'status' => 'completed',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('order_items')->insert(['order_id' => $orderId, 'product_id' => $productId, 'product_size_id' => $sizeId, 'quantity' => 2, 'price_at_time' => 50, 'original_price' => 50, 'total_price' => 100, 'created_at' => $now, 'updated_at' => $now]);
        // An open order: its 3 units already left product_sizes.stock at checkout, but are still on the shelf.
        $openOrderId = DB::table('orders')->insertGetId(['user_id' => $userId, 'payment_method' => 'cash', 'delivery_fee' => 0, 'total_price' => 150, 'points_earned' => 0, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
        $openItemId = DB::table('order_items')->insertGetId(['order_id' => $openOrderId, 'product_id' => $productId, 'product_size_id' => $sizeId, 'quantity' => 3, 'price_at_time' => 50, 'original_price' => 50, 'total_price' => 150, 'created_at' => $now, 'updated_at' => $now]);

        // ---- run the new migrations
        $this->assertSame(0, Artisan::call('migrate'));

        // Delivery prices became zones with the same fee, matched to cities by exact name.
        $this->assertEquals(5, DB::table('delivery_rates')->join('delivery_zones', 'delivery_zones.id', '=', 'delivery_rates.delivery_zone_id')->where('delivery_zones.name', 'Cairo')->value('fee'));
        $alexandria = DB::table('cities')->where('name', 'الإسكندرية')->first();
        $this->assertNotNull($alexandria, 'a city is created for a priced governorate that had none');
        $this->assertSame($alexandria->id, DB::table('addresses')->where('id', $addressId)->value('city_id'));
        $this->assertSame(2, DB::table('delivery_prices')->count(), 'the legacy table is kept');

        // The order: money untouched, snapshot and status filled.
        $order = DB::table('orders')->find($orderId);
        $this->assertEquals(97.5, $order->total_price);
        $this->assertEquals(100, $order->subtotal);
        $this->assertEquals(10, $order->discount_amount);
        $this->assertSame('paid', $order->payment_status);
        // The store operates in Egypt only: backfilled orders are recorded in EGP.
        $this->assertSame('EGP', $order->currency_code);
        $this->assertSame('Old street', json_decode($order->shipping_address, true)['street']);
        $this->assertSame(DB::table('payment_methods')->where('code', 'zaincash')->value('id'), $order->payment_method_id);
        $this->assertSame('01001234567', DB::table('payment_methods')->where('code', 'zaincash')->value('account_number'));

        // ZainCash retired: its setting column is gone, the number survives on the legacy method,
        // and the historical ZainCash order is untouched.
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('settings', 'zaincash'));
        $this->assertSame(1, (int) DB::table('payment_methods')->where('code', 'zaincash')->value('is_legacy'));
        $this->assertSame(0, (int) DB::table('payment_methods')->where('code', 'zaincash')->value('is_active'));
        $this->assertSame('zaincash', DB::table('orders')->where('id', $orderId)->value('payment_method'));
        $this->assertSame('payments/r.jpg', DB::table('orders')->where('id', $orderId)->value('payment_image'));
        $this->assertSame(1, DB::table('order_status_histories')->where('order_id', $orderId)->count());

        // Points history went into the ledger.
        $this->assertSame([['earn', 10], ['redeem', -10]], DB::table('loyalty_ledger')->where('order_id', $orderId)->orderBy('id')->get()->map(fn ($e) => [$e->type, (int) $e->points])->all());

        // Existing admins and users keep working; health content is seeded.
        $this->assertSame(1, (int) DB::table('users')->where('id', $userId)->value('is_active'));
        $this->assertSame(9, DB::table('vaccine_schedule_items')->count());
        $this->assertSame(7, DB::table('pregnancy_week_contents')->count());

        // Inventory: one default warehouse; the open order's units become a reservation, so the
        // sellable stock the app sees (5) is unchanged and the shelf count (8) is right.
        $warehouseId = DB::table('warehouses')->where('is_default', true)->value('id');
        $balance = DB::table('inventory_balances')->where(['warehouse_id' => $warehouseId, 'product_size_id' => $sizeId])->first();
        $this->assertSame([8, 3], [(int) $balance->on_hand, (int) $balance->reserved]);
        $this->assertSame(5, (int) DB::table('product_sizes')->where('id', $sizeId)->value('stock'));
        $this->assertSame(3, (int) DB::table('stock_reservations')->where(['order_item_id' => $openItemId, 'status' => 'active'])->value('quantity'));
        $this->assertSame('reserved', DB::table('order_items')->where('id', $openItemId)->value('stock_state'));
        $this->assertSame('fulfilled', DB::table('order_items')->where('order_id', $orderId)->value('stock_state'));
        $this->assertSame([8, 3], [
            (int) DB::table('stock_movements')->where(['product_size_id' => $sizeId, 'bucket' => 'on_hand'])->sum('quantity_change'),
            (int) DB::table('stock_movements')->where(['product_size_id' => $sizeId, 'bucket' => 'reserved'])->sum('quantity_change'),
        ], 'opening balance rows make the ledger sum to the balances');

        // Financial history can no longer be deleted with the customer.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('users')->where('id', $userId)->delete();
    }

    public function test_the_new_migrations_roll_back(): void
    {
        Artisan::call('migrate:fresh');

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => collect(File::files(database_path('migrations')))->filter(fn ($f) => self::isNew($f->getPathname()))->count()]));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('shipments'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('orders', 'shipping_address'));
        $this->assertSame(0, Artisan::call('migrate'));
    }
}
