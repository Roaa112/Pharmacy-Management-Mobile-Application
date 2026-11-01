<?php

namespace Tests\Feature\Orders;

use App\Models\Admin;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * ZainCash has been removed as an active payment provider. Historical ZainCash records are preserved.
 */
class ZainCashRetirementTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->admin = Admin::create(['name' => 'Super', 'email' => 'super@example.com', 'password' => 'secret-password']);
        Sanctum::actingAs($this->user);
    }

    public function test_zaincash_is_not_offered_or_accepted_and_cash_still_works(): void
    {
        $this->getJson('/api/v1/user/payment-methods')->assertOk()
            ->assertJsonMissing(['code' => 'zaincash'])
            ->assertJsonFragment(['code' => 'cash']);

        $this->putInCart($this->user, $this->makeSize(price: 25), 1);
        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'zaincash'])
            ->assertStatus(422)->assertJsonValidationErrors('payment_method');
        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated();
    }

    public function test_the_zaincash_setting_is_gone_and_the_settings_api_reports_it_unavailable(): void
    {
        $this->assertFalse(Schema::hasColumn('settings', 'zaincash'));
        $this->assertNotContains('zaincash', (new Setting())->getFillable());

        // Older apps read this key; it is always null now, so they stop offering ZainCash.
        Setting::current();
        $this->getJson('/api/v1/user/settings')->assertOk()->assertJsonPath('data.0.zaincash', null);

        // Sending it through the dashboard settings form changes nothing.
        $this->actingAs($this->admin, 'admin')->put(route('dashboard.settings.update', Setting::current()), ['zaincash' => '01000000000'])->assertRedirect();
        $this->assertNull(Setting::current()->zaincash);
    }

    public function test_admin_cannot_configure_or_reactivate_zaincash(): void
    {
        $zaincash = PaymentMethod::where('code', 'zaincash')->firstOrFail();

        $page = $this->actingAs($this->admin, 'admin')->get(route('dashboard.payment_methods.index'))->assertOk();
        $page->assertSee('Retired methods (history only)');
        $page->assertDontSee('editMethod'.$zaincash->id, false);

        $this->actingAs($this->admin, 'admin')->put(route('dashboard.payment_methods.update', $zaincash), [
            'name_ar' => 'زين كاش', 'name_en' => 'Zain Cash', 'is_active' => 1, 'account_number' => '0799',
        ])->assertSessionHas('error');

        $zaincash->refresh();
        $this->assertFalse($zaincash->is_active);
        $this->assertTrue($zaincash->is_legacy);
        $this->assertNotSame('0799', $zaincash->account_number);

        // A new method cannot be created as a manual transfer either.
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.payment_methods.store'), [
            'provider' => 'manual_transfer', 'code' => 'zaincash2', 'name_ar' => 'ز', 'name_en' => 'Z', 'is_active' => 1,
        ])->assertSessionHasErrors('provider');
        $this->assertFalse(PaymentMethod::where('code', 'zaincash2')->exists());
    }

    public function test_historical_zaincash_orders_remain_visible_everywhere(): void
    {
        $order = $this->makeHistoricalZainCashOrder($this->user, 'paid', 'completed');

        // Customer app.
        $this->getJson("/api/user/order-details/{$order->id}")->assertOk()->assertJsonPath('payment_method', 'zaincash');
        $this->getJson('/api/user/orders/grouped-by-status')->assertOk()->assertJsonPath('data.completed.0.order_id', $order->id);

        // Dashboard: order page, list filter, reports.
        $this->actingAs($this->admin, 'admin')->get(route('dashboard.orders.show', $order))->assertOk()->assertSee('Zain Cash');
        $this->actingAs($this->admin, 'admin')->get(route('dashboard.orders.index', ['payment_method' => 'zaincash']))->assertOk()->assertSee('#'.$order->id);
        $this->actingAs($this->admin, 'admin')->get(route('dashboard.reports.index'))->assertOk()->assertSee('zaincash');

        // The record itself is unchanged.
        $fresh = Order::find($order->id);
        $this->assertSame('zaincash', $fresh->payment_method);
        $this->assertSame(PaymentMethod::where('code', 'zaincash')->value('id'), $fresh->payment_method_id);
        $this->assertSame('payments/zaincash-receipt.jpg', $fresh->payment_image);
    }

    public function test_a_historical_zaincash_receipt_can_still_be_verified(): void
    {
        $order = $this->makeHistoricalZainCashOrder($this->user);

        $this->actingAs($this->admin, 'admin')->patch(route('dashboard.orders.paymentStatus', $order), ['payment_status' => 'paid'])->assertRedirect();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('zaincash', $order->fresh()->payment_method);
    }
}
