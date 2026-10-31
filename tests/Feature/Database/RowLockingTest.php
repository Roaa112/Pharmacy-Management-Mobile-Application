<?php

namespace Tests\Feature\Database;

use App\Models\ProductSize;
use App\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Proves on a real MySQL/MariaDB server that the stock lock used by checkout blocks a second
 * connection. SQLite has no row locks, so this test only runs against MySQL/MariaDB:
 *
 *   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=pharmacy_test \
 *   DB_USERNAME=... DB_PASSWORD=... php artisan test
 */
class RowLockingTest extends TestCase
{
    // Real commits are needed so the second connection can see the rows.
    use DatabaseMigrations, BuildsShop;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Row locking can only be verified on MySQL/MariaDB.');
        }

        config(['database.connections.concurrent' => config('database.connections.' . DB::getDefaultConnection())]);
    }

    public function test_a_locked_size_cannot_be_locked_by_a_second_connection(): void
    {
        $size = $this->makeSize(stock: 5);
        $other = DB::connection('concurrent');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::beginTransaction();
        ProductSize::lockForUpdate()->findOrFail($size->id); // what checkout does

        try {
            $other->table('product_sizes')->where('id', $size->id)->lockForUpdate()->first();
            $this->fail('The second connection should have waited for the lock and timed out.');
        } catch (QueryException $e) {
            $this->assertSame(1205, $e->errorInfo[1] ?? null, 'Expected "Lock wait timeout exceeded".');
        } finally {
            DB::rollBack();
        }

        // Once released, the other connection can take it.
        $this->assertNotNull($other->table('product_sizes')->where('id', $size->id)->lockForUpdate()->first());
    }

    public function test_a_stock_change_waits_for_the_running_checkout_and_sees_its_result(): void
    {
        $size = $this->makeSize(stock: 1);
        $other = DB::connection('concurrent');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');

        // Checkout A holds the lock and sells the last unit, but has not committed yet.
        DB::beginTransaction();
        ProductSize::moveStock($size->id, -1, StockMovement::TYPE_SALE);

        // Checkout B cannot read-for-update a stale "1" while A is in flight.
        try {
            $other->table('product_sizes')->where('id', $size->id)->lockForUpdate()->value('stock');
            $this->fail('Checkout B must not read the row while A holds the lock.');
        } catch (QueryException $e) {
            $this->assertSame(1205, $e->errorInfo[1] ?? null);
        }

        DB::commit();

        // After A commits, B sees 0 and would refuse the sale.
        $this->assertSame(0, (int) $other->table('product_sizes')->where('id', $size->id)->lockForUpdate()->value('stock'));
    }
}
