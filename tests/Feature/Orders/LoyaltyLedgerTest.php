<?php

namespace Tests\Feature\Orders;

use App\Models\Admin;
use App\Models\LoyaltyLedgerEntry;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Orders\Services\ReturnDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class LoyaltyLedgerTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::current()->update(['spend_x' => '10', 'get_y' => '1', 'points_validity_days' => 30]);
        $this->user = User::factory()->create();
        $this->admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-password']);
        Sanctum::actingAs($this->user);
    }

    private function checkout(float $price = 100): Order
    {
        $this->putInCart($this->user, $this->makeSize(price: $price, stock: 5), 1);

        return Order::findOrFail($this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated()->json('order_id'));
    }

    private function ledger(Order $order): array
    {
        return LoyaltyLedgerEntry::where('order_id', $order->id)->orderBy('id')->get(['type', 'points'])->map(fn ($e) => [$e->type, $e->points])->all();
    }

    public function test_every_points_movement_is_in_the_ledger_and_api_matches_dashboard(): void
    {
        $order = $this->checkout(100); // 10 points
        $order->forceFill(['status' => 'completed'])->save();
        $this->assertSame([['earn', 10]], $this->ledger($order));

        // A 30 % refund reverses 3 points.
        $return = ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'reason' => 'x', 'refund_amount' => 30]);
        app(ReturnDecisionService::class)->accept($return->id, null, [], $this->admin->id);
        $this->assertSame([['earn', 10], ['reverse', -3]], $this->ledger($order));

        $this->getJson('/api/user/points/valid')->assertJsonPath('valid_points_total', 7)->assertJsonPath('orders.0.points_original', 10);
        $this->actingAs($this->admin, 'admin')->get(route('dashboard.points.index'))->assertOk()->assertViewHas('valid_points_total', 7);

        $this->actingAs($this->admin, 'admin')->patch(route('dashboard.points.redeem', $order))->assertRedirect();
        $this->assertSame([['earn', 10], ['reverse', -3], ['redeem', -7]], $this->ledger($order));
        $this->getJson('/api/user/points/notvalid')->assertJsonPath('consumed_points_total', 7);

        // Redeeming twice does nothing.
        $this->actingAs($this->admin, 'admin')->patch(route('dashboard.points.redeem', $order));
        $this->assertCount(3, $this->ledger($order));
    }

    public function test_a_full_refund_marks_the_payment_refunded(): void
    {
        $order = $this->checkout(50);
        // Completed through the workflow: the cash is recorded as collected (pickup at the counter).
        app(\App\Modules\Orders\Services\OrderWorkflow::class)->transition($order, \App\Enums\OrderStatus::Completed, 'admin', $this->admin->id);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $return = ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'reason' => 'x', 'refund_amount' => 50]);

        app(ReturnDecisionService::class)->accept($return->id, null, [], $this->admin->id);

        // Accepting creates a refund to pay back; the payment shows refunded only once it is paid.
        $refund = \App\Models\Refund::where('return_request_id', $return->id)->sole();
        $this->assertSame(['pending', 'cash'], [$refund->status, $refund->method]);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(0, $this->getJson('/api/user/points/valid')->json('valid_points_total'));

        app(\App\Modules\Payments\Services\RefundService::class)->process($refund, $this->admin->id, 'Cash voucher 17');

        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame('full', $order->fresh()->refund_status);
        $this->assertEquals(50, $order->fresh()->refunded_amount);
    }

    public function test_cancel_voids_the_earn_and_expiry_is_recorded_once(): void
    {
        $canceled = $this->checkout(100);
        app(\App\Modules\Orders\Services\OrderWorkflow::class)->transition($canceled, \App\Enums\OrderStatus::Canceled, 'admin', $this->admin->id);
        $this->assertSame([['earn', 10], ['reverse', -10]], $this->ledger($canceled));

        $old = $this->checkout(200);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();

        $this->artisan('loyalty:record-expirations')->assertSuccessful();
        $this->artisan('loyalty:record-expirations')->assertSuccessful();
        $this->assertSame([['earn', 20], ['expire', -20]], $this->ledger($old));
        $this->getJson('/api/user/points/expired')->assertJsonPath('expired_points_total', 20);
    }

    public function test_ledger_entries_cannot_be_changed(): void
    {
        $this->checkout();

        $this->expectException(LogicException::class);
        LoyaltyLedgerEntry::firstOrFail()->update(['points' => 1000]);
    }

    public function test_points_settings_are_validated(): void
    {
        $setting = Setting::current();
        $put = fn (array $data) => $this->actingAs($this->admin, 'admin')->put(route('dashboard.settings.update', $setting), $data);

        $put(['spend_x' => 'abc'])->assertSessionHasErrors('spend_x');
        $put(['get_y' => -1])->assertSessionHasErrors('get_y');
        $put(['get_y' => 1.5])->assertSessionHasErrors('get_y');
        $put(['points_validity_days' => 99999])->assertSessionHasErrors('points_validity_days');
        $put(['return_window_days' => 0])->assertSessionHasErrors('return_window_days');

        $put(['spend_x' => '25', 'get_y' => '2'])->assertSessionHasNoErrors();
        $this->assertSame(2500, $setting->fresh()->pointsSpendMinor());
        // A partial save does not clear the rest.
        $this->assertEquals(30, $setting->fresh()->points_validity_days);
    }
}
