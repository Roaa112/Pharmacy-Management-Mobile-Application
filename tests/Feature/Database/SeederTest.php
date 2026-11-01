<?php

namespace Tests\Feature\Database;

use App\Models\Order;
use App\Models\ProductSize;
use App\Models\User;
use Database\Seeders\Demo\CustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    /** Framework tables, filled by the framework itself. */
    private const NOT_SEEDED = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'personal_access_tokens', 'sessions', 'password_reset_tokens',
        // Admin_Email / Admin_Password are not set in the test environment.
        'admins',
        // Written only by admins' own actions in the dashboard.
        'admin_audit_logs',
    ];

    private function appTables(): array
    {
        return collect(Schema::getTableListing(schemaQualified: false))
            ->reject(fn ($table) => in_array($table, self::NOT_SEEDED, true) || str_starts_with($table, 'sqlite_'))
            ->values()
            ->all();
    }

    public function test_every_application_table_gets_data(): void
    {
        $this->seed();

        $empty = array_values(array_filter($this->appTables(), fn ($table) => DB::table($table)->count() === 0));

        $this->assertSame([], $empty);
    }

    public function test_seeded_stock_matches_the_ledger_and_coupon_usage_matches_its_users(): void
    {
        $this->seed();

        // Balances = ledger, reserved = active reservations, catalogue stock = balances.
        $this->assertSame([], app(\App\Modules\Inventory\Services\InventoryIntegrity::class)->problems());

        foreach (DB::table('coupons')->get() as $coupon) {
            $this->assertSame((int) $coupon->used_count, DB::table('coupon_user')->where('coupon_id', $coupon->id)->count(), $coupon->code);
            $this->assertLessThanOrEqual((int) $coupon->usage_limit, (int) $coupon->used_count, $coupon->code);
        }

        // Every order line's stock state follows its order: open lines reserved, dispatched lines
        // fulfilled, canceled lines released.
        foreach (Order::withoutGlobalScopes()->with('items')->get() as $order) {
            $expected = match (true) {
                $order->status === 'canceled' => 'released',
                \App\Enums\OrderStatus::from($order->status)->isDispatched() => 'fulfilled',
                default => 'reserved',
            };
            foreach ($order->items as $item) {
                $this->assertSame($expected, $item->stock_state, "order {$order->id}");
            }
        }
    }

    public function test_seeding_twice_does_not_duplicate_anything(): void
    {
        $this->seed();
        $counts = collect($this->appTables())->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);

        $this->seed();

        $this->assertSame($counts->all(), collect($this->appTables())->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all());
    }

    public function test_demo_data_is_never_seeded_in_production(): void
    {
        $this->app['env'] = 'production';

        // In production db:seed asks for confirmation unless --force is given, as in a deploy.
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(1, DB::table('settings')->count());
        $this->assertGreaterThan(0, DB::table('delivery_prices')->count());
    }

    public function test_demo_customer_can_sign_in_and_use_the_app(): void
    {
        $this->seed();

        $token = $this->postJson('/api/user/login', ['email' => 'sara@example.com', 'password' => CustomerSeeder::PASSWORD])
            ->assertOk()
            ->json('original.token');
        $this->assertNotEmpty($token);

        $this->withToken($token)->getJson('/api/user/cart')->assertOk();
        $this->withToken($token)->getJson('/api/user/orders/grouped-by-status')->assertOk()->assertJsonPath('success', true);
        $this->withToken($token)->getJson('/api/user/health/ovulation/current')->assertOk()->assertJsonPath('today.phase_code', fn ($code) => is_string($code));
        $this->withToken($token)->getJson('/api/user/medication')->assertOk();
        $this->getJson('/api/v1/user/products')->assertOk()->assertJsonPath('status', 'success');
    }
}
