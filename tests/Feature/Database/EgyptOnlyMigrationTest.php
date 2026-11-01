<?php

namespace Tests\Feature\Database;

use App\Models\City;
use App\Models\Country;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Modules\Reports\Revenue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * The store currently operates in Egypt only and uses EGP. 2026_11_06_000001_make_store_egypt_only
 * corrects databases migrated with the earlier multi-country default without touching history.
 */
class EgyptOnlyMigrationTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private function runMigration(): void
    {
        (require database_path('migrations/2026_11_06_000001_make_store_egypt_only.php'))->up();
        Country::flushDefault();
    }

    public function test_a_fresh_install_has_egypt_only(): void
    {
        $this->assertSame([['EG', 'EGP', 'Africa/Cairo', 1, 1]], Country::get()->map(fn ($c) => [$c->iso2, $c->currency_code, $c->timezone, (int) $c->is_active, (int) $c->is_default])->all());
        $this->assertSame(0, PaymentMethod::where('country_id', '!=', Country::default()->id)->count());

        $this->runMigration(); // a no-op here
        $this->assertSame(1, Country::count());
    }

    public function test_an_old_multi_country_database_is_moved_to_egypt_and_history_is_kept(): void
    {
        // As an earlier version left it: another market as default, setup attached to it.
        $egypt = Country::default();
        $egypt->update(['is_active' => false, 'is_default' => false]);
        $old = Country::create(['iso2' => 'XX', 'name_ar' => 'قديم', 'name_en' => 'Old default', 'currency_code' => 'XXX', 'timezone' => 'UTC', 'phone_code' => '+0', 'is_active' => true, 'is_default' => true]);
        $unused = Country::create(['iso2' => 'YY', 'name_ar' => 'غير مستخدم', 'name_en' => 'Unused', 'currency_code' => 'YYY', 'timezone' => 'UTC', 'phone_code' => '+0']);

        $city = City::create(['name' => 'Alexandria', 'country_id' => $old->id]);
        PaymentMethod::where('code', 'cash')->update(['country_id' => $old->id]);
        $order = $this->makeOrder(User::factory()->create(), $this->makeSize());
        $order->forceFill(['country_id' => $old->id, 'currency_code' => 'XXX'])->save();

        $this->runMigration();

        $egypt->refresh();
        $this->assertTrue($egypt->is_active);
        $this->assertTrue($egypt->is_default);
        $this->assertSame('EGP', $egypt->currency_code);
        $this->assertSame('Africa/Cairo', $egypt->timezone);

        // Setup moved to Egypt.
        $this->assertSame($egypt->id, $city->fresh()->country_id);
        $this->assertSame($egypt->id, PaymentMethod::where('code', 'cash')->value('country_id'));

        // History untouched: the order keeps what was recorded, and its country row stays (inactive).
        $this->assertSame('XXX', $order->fresh()->currency_code);
        $this->assertSame($old->id, $order->fresh()->country_id);
        $this->assertFalse($old->fresh()->is_active);
        $this->assertFalse($old->fresh()->is_default);

        // A country nothing refers to is removed.
        $this->assertNull(Country::find($unused->id));
    }

    public function test_reports_group_by_egyptian_local_day_through_summer_time(): void
    {
        $user = User::factory()->create();
        $size = $this->makeSize(price: 10);
        // 22:30 UTC: 01:30 next day in summer (UTC+3), 00:30 next day in winter (UTC+2).
        // 21:30 UTC: 00:30 next day in summer, 23:30 the same day in winter.
        $at = ['2026-07-15 21:30:00', '2026-12-15 21:30:00', '2026-12-15 22:30:00'];
        foreach ($at as $time) {
            $this->makeOrder($user, $size, status: 'completed')->forceFill(['created_at' => $time])->saveQuietly();
        }

        $days = Order::query()->selectRaw(Revenue::localDate('orders.created_at').' as day')->orderBy('id')->pluck('day')->all();

        $this->assertSame(['2026-07-16', '2026-12-15', '2026-12-16'], $days);
    }
}
