<?php

namespace Tests\Feature\Returns;

use App\Models\Admin;
use App\Models\LoyaltyPointReversal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductSize;
use App\Models\ReturnRequest;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use RuntimeException;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Decision D1 (returned medicine is quarantined, never resold automatically) and
 * decision D2 (loyalty points are reversed in proportion to the money refunded).
 */
class ReturnQuarantineAndPointsTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private Admin $admin;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password']);
        $this->customer = User::factory()->create();
        // 1 point per 1.00 spent on products.
        Setting::create(['spend_x' => 1, 'get_y' => 1, 'points_validity_days' => 30]);
    }

    /** A completed order bought through the real checkout: 2 × 50.00, delivery 0, 100 points. */
    private function completedOrder(int $quantity = 2, float $price = 50, ?ProductSize $size = null): Order
    {
        $size ??= $this->makeSize(price: $price, stock: 10);
        Sanctum::actingAs($this->customer);
        $this->putInCart($this->customer, $size, $quantity);
        $orderId = $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertCreated()->json('order_id');

        $order = Order::findOrFail($orderId);
        $order->update(['status' => 'completed']);

        return $order->fresh();
    }

    private function requestReturn(Order $order, $amount = null): ReturnRequest
    {
        Sanctum::actingAs($this->customer);
        $this->postJson('/api/user/returns', array_filter(['order_id' => $order->id, 'reason' => 'damaged', 'refund_amount' => $amount], fn ($v) => $v !== null))
            ->assertCreated();

        return ReturnRequest::latest('id')->first();
    }

    private function accept(ReturnRequest $return, array $data = [])
    {
        return $this->actingAs($this->admin, 'admin')->from("/dashboard/returns/{$return->id}")
            ->post("/dashboard/returns/{$return->id}/accept", $data);
    }

    private function line(Order $order): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->firstOrFail();
    }

    // ---------- D1: quarantine ----------

    public function test_accepting_a_return_puts_units_in_quarantine_not_back_on_sale(): void
    {
        $order = $this->completedOrder();
        $line = $this->line($order);
        $sizeId = $line->product_size_id;
        $this->assertSame(8, ProductSize::find($sizeId)->stock);

        $this->accept($this->requestReturn($order, 50), ['quantities' => [$line->id => 1]])->assertSessionHas('success');

        $size = ProductSize::find($sizeId);
        $this->assertSame(8, $size->stock, 'Sellable stock must not change');
        $this->assertSame(1, $size->quarantined_stock);

        $movement = StockMovement::where('type', StockMovement::TYPE_RETURN_QUARANTINE)->sole();
        $this->assertSame('quarantine', $movement->bucket);
        $this->assertSame([0, 1, 1], [$movement->quantity_before, $movement->quantity_change, $movement->quantity_after]);
        $this->assertSame($this->admin->id, $movement->admin_id);
        $this->assertStringContainsString("order #{$order->id}", $movement->reason);
        // Linked to the return line, which links to the original order line.
        $this->assertSame($line->id, \App\Models\ReturnRequestItem::find($movement->reference_id)->order_item_id);

        // The original sale movement is untouched.
        $this->assertSame(1, StockMovement::where('type', 'sale')->count());
    }

    public function test_quarantined_units_cannot_be_bought(): void
    {
        $size = $this->makeSize(price: 50, stock: 1);
        $order = $this->completedOrder(quantity: 1, size: $size);
        $this->accept($this->requestReturn($order, 50), ['quantities' => [$this->line($order)->id => 1]]);

        $this->assertSame(0, $size->fresh()->stock);
        $this->assertSame(1, $size->fresh()->quarantined_stock);

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->postJson('/api/user/cart', ['product_id' => $size->product_id, 'product_size' => $size->id, 'quantity' => 1])->assertStatus(400);
        $this->putInCart($other, $size, 1);
        $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash'])->assertStatus(422);

        $product = $this->getJson("/api/v1/user/show/{$size->product_id}/product")->assertOk()->json('data');
        $this->assertSame('out_of_stock', $product['stock_status']);
        $this->assertSame(0, $product['size_options'][0]['stock']);
    }

    public function test_a_return_can_be_accepted_without_receiving_goods(): void
    {
        $order = $this->completedOrder();

        $this->accept($this->requestReturn($order, 10))->assertSessionHas('success');

        $this->assertSame(0, StockMovement::where('type', StockMovement::TYPE_RETURN_QUARANTINE)->count());
        $this->assertSame(0, ProductSize::sole()->quarantined_stock);
    }

    public function test_repeated_accept_does_not_duplicate_movements_or_reversals(): void
    {
        $order = $this->completedOrder();
        $return = $this->requestReturn($order, 50);
        $data = ['quantities' => [$this->line($order)->id => 1]];

        $this->accept($return, $data)->assertSessionHas('success');
        $this->accept($return, $data)->assertSessionHas('error');

        $this->assertSame(1, StockMovement::where('type', StockMovement::TYPE_RETURN_QUARANTINE)->count());
        $this->assertSame(1, ProductSize::sole()->quarantined_stock);
        $this->assertSame(1, LoyaltyPointReversal::count());
    }

    public function test_more_units_than_were_ordered_cannot_be_returned_across_returns(): void
    {
        $order = $this->completedOrder(quantity: 2);
        $lineId = $this->line($order)->id;

        $this->accept($this->requestReturn($order, 10), ['quantities' => [$lineId => 2]])->assertSessionHas('success');
        $second = $this->requestReturn($order, 10);
        $this->accept($second, ['quantities' => [$lineId => 1]])->assertSessionHas('error');

        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(2, ProductSize::sole()->quarantined_stock);
    }

    public function test_a_line_of_another_order_cannot_be_quarantined(): void
    {
        $order = $this->completedOrder();
        $otherLine = $this->line($this->completedOrder());

        $this->accept($this->requestReturn($order, 10), ['quantities' => [$otherLine->id => 1]])->assertSessionHas('error');

        $this->assertSame(0, ProductSize::sum('quarantined_stock'));
    }

    public function test_accepted_refunds_cannot_exceed_the_order_total(): void
    {
        $order = $this->completedOrder(); // 100.00
        $first = $this->requestReturn($order, 60);
        $second = $this->requestReturn($order, 40);

        // The admin raises the first to 70: the second (40) would now overshoot.
        $this->accept($first, ['approved_amount' => 70])->assertSessionHas('success');
        $this->accept($second)->assertSessionHas('error');
        $this->accept($second, ['approved_amount' => 30])->assertSessionHas('success');

        $this->assertEquals(70, $first->fresh()->approved_amount);
        $this->assertEquals(30, $second->fresh()->approved_amount);
        // Nothing left: the customer cannot request more.
        Sanctum::actingAs($this->customer);
        $this->postJson('/api/user/returns', ['order_id' => $order->id, 'reason' => 'x', 'refund_amount' => 0.01])->assertStatus(422);
    }

    public function test_rejected_returns_change_neither_stock_nor_points(): void
    {
        $order = $this->completedOrder();
        $return = $this->requestReturn($order, 50);

        $this->actingAs($this->admin, 'admin')->post("/dashboard/returns/{$return->id}/reject", ['reason' => 'Opened package'])
            ->assertSessionHas('success');

        $this->assertSame('rejected', $return->fresh()->status);
        $this->assertSame($this->admin->id, $return->fresh()->decided_by);
        $this->assertSame(8, ProductSize::sole()->stock);
        $this->assertSame(0, ProductSize::sole()->quarantined_stock);
        $this->assertSame(0, LoyaltyPointReversal::count());
        $this->assertSame(1, StockMovement::count() - StockMovement::where('type', 'initial')->count());
    }

    public function test_refund_without_received_goods_does_not_touch_inventory(): void
    {
        $order = $this->completedOrder();
        $before = StockMovement::count();

        $this->accept($this->requestReturn($order, 100));

        $this->assertSame($before, StockMovement::count());
        $this->assertSame(8, ProductSize::sole()->stock);
    }

    public function test_order_history_stays_intact_after_a_return(): void
    {
        $order = $this->completedOrder();
        $this->accept($this->requestReturn($order, 50), ['quantities' => [$this->line($order)->id => 2]]);

        $order->refresh();
        $line = $this->line($order);
        $this->assertSame(2, $line->quantity);
        $this->assertEquals(50, $line->price_at_time);
        $this->assertEquals(100, $order->total_price);
        $this->assertSame(100, (int) $order->points_earned);
        $this->assertSame('completed', $order->status);
    }

    public function test_ledger_rows_cannot_be_edited_or_deleted(): void
    {
        $this->completedOrder();
        $movement = StockMovement::where('type', 'sale')->sole();

        try {
            $movement->update(['quantity_change' => 0]);
            $this->fail('A stock movement was edited');
        } catch (LogicException) {
        }

        try {
            $movement->delete();
            $this->fail('A stock movement was deleted');
        } catch (LogicException) {
        }

        $this->assertSame(-2, $movement->fresh()->quantity_change);
    }

    public function test_return_admin_pages_render_before_and_after_the_decision(): void
    {
        $order = $this->completedOrder();
        $return = $this->requestReturn($order, 25);

        $this->actingAs($this->admin, 'admin')->get("/dashboard/returns/{$return->id}")
            ->assertOk()->assertSee('Units received back')->assertSee('quantities['.$this->line($order)->id.']', false);

        $this->accept($return, ['quantities' => [$this->line($order)->id => 1]]);

        $this->actingAs($this->admin, 'admin')->get("/dashboard/returns/{$return->id}")
            ->assertOk()->assertSee('Points reversed')->assertSee('25');
        $this->actingAs($this->admin, 'admin')->get('/dashboard/inventory/movements?type=return_quarantine')
            ->assertOk()->assertSee("Return #{$return->id}");
        $this->actingAs($this->admin, 'admin')->get('/dashboard/inventory')->assertOk()->assertSee('returned units in quarantine');
    }

    // ---------- D2: proportional point reversal ----------

    public function test_25_percent_refund_reverses_25_percent_of_points(): void
    {
        $order = $this->completedOrder();
        $this->accept($this->requestReturn($order, 25));

        $this->assertSame(25, LoyaltyPointReversal::sole()->points);
    }

    public function test_50_percent_and_full_refunds(): void
    {
        $half = $this->completedOrder();
        $this->accept($this->requestReturn($half, 50));
        $this->assertSame(50, LoyaltyPointReversal::where('order_id', $half->id)->sum('points'));

        $full = $this->completedOrder();
        $this->accept($this->requestReturn($full, 100));
        $this->assertSame(100, (int) LoyaltyPointReversal::where('order_id', $full->id)->sum('points'));
    }

    public function test_cumulative_partial_refunds_round_once_and_never_exceed_earned(): void
    {
        // 7 points on a 7.00 order: 10% + 20% + 70% refunds.
        $order = $this->completedOrder(quantity: 1, price: 7);
        $this->assertSame(7, (int) $order->points_earned);

        foreach ([0.70, 1.40, 4.90] as $amount) {
            $this->accept($this->requestReturn($order, $amount))->assertSessionHas('success');
        }

        $reversals = LoyaltyPointReversal::orderBy('id')->get();
        // Cumulative targets: round(0.7)=1, round(2.1)=2, 7 → 1 + 1 + 5.
        $this->assertSame([1, 1, 5], $reversals->pluck('points')->all());
        $this->assertSame([1, 2, 7], $reversals->pluck('reversed_total')->all());
        $this->assertSame(7, (int) $reversals->sum('points'));
    }

    public function test_independent_rounding_would_drift_but_cumulative_does_not(): void
    {
        // 3 points on 3.00; three refunds of 1.00 each = 33.3% → never 0+0+0 or 1+1+1+extra.
        $order = $this->completedOrder(quantity: 1, price: 3);
        foreach ([1, 1, 1] as $amount) {
            $this->accept($this->requestReturn($order, $amount));
        }

        $this->assertSame(3, (int) LoyaltyPointReversal::sum('points'));
    }

    public function test_delivery_fee_is_not_part_of_the_point_base(): void
    {
        // Products 100.00 + delivery 20.00 = 120.00 paid; points earned on 100.00.
        $size = $this->makeSize(price: 50, stock: 10);
        $this->makeDeliveryPrice('Baghdad', 20);
        $address = $this->makeAddress($this->customer);
        Sanctum::actingAs($this->customer);
        $this->putInCart($this->customer, $size, 2);
        $orderId = $this->postJson('/api/user/orders', ['address_id' => $address->id, 'payment_method' => 'cash'])->assertCreated()->json('order_id');
        $order = Order::find($orderId);
        $this->assertSame(100, (int) $order->points_earned);

        // Refunding the products' share (100.00) reverses everything; 25.00 reverses 25.
        $this->accept($this->requestReturn($order, 25));
        $this->assertSame(25, LoyaltyPointReversal::sole()->points);
        $this->accept($this->requestReturn($order, 75));
        $this->assertSame(100, (int) LoyaltyPointReversal::sum('points'));
    }

    public function test_coupon_discount_is_handled_proportionally(): void
    {
        // 100.00 of products, 10% coupon → paid 90.00; points earned on 100.00 as before.
        \App\Models\Coupon::create(['code' => 'TEN', 'discount_value' => 10, 'is_active' => true]);
        $size = $this->makeSize(price: 50, stock: 10);
        Sanctum::actingAs($this->customer);
        $this->putInCart($this->customer, $size, 2);
        $orderId = $this->postJson('/api/user/orders', ['address_id' => -1, 'payment_method' => 'cash', 'coupon_code' => 'TEN'])
            ->assertCreated()->assertJsonPath('total_price', 90)->json('order_id');
        $order = Order::find($orderId);

        // Half of what was paid → half the points.
        $this->accept($this->requestReturn($order, 45));
        $this->assertSame(50, LoyaltyPointReversal::sole()->points);
    }

    public function test_reversal_of_already_redeemed_points_is_recorded_as_owed(): void
    {
        $order = $this->completedOrder();
        $this->actingAs($this->admin, 'admin')->patch("/dashboard/points/{$order->id}/redeem");
        $this->assertSame('redeemed', $order->fresh()->points_status);

        $this->accept($this->requestReturn($order, 40));

        $reversal = LoyaltyPointReversal::sole();
        $this->assertSame(40, $reversal->points);
        $this->assertSame('redeemed', $reversal->points_status_at_reversal);

        $this->actingAs($this->admin, 'admin')->get("/dashboard/customers/{$this->customer->id}")
            ->assertOk()->assertSee('40 owed after refunds of redeemed points');
    }

    public function test_partially_reversed_points_redeem_net_and_fully_reversed_cannot_be_redeemed(): void
    {
        $partial = $this->completedOrder();
        $this->accept($this->requestReturn($partial, 30));

        $page = $this->actingAs($this->admin, 'admin')->get('/dashboard/points')->assertOk();
        $this->assertSame(70, $page->viewData('valid_points_total'));

        $full = $this->completedOrder();
        $this->accept($this->requestReturn($full, 100));
        $this->actingAs($this->admin, 'admin')->from('/dashboard/points')->patch("/dashboard/points/{$full->id}/redeem")
            ->assertSessionHas('error');
        $this->assertSame('earned', $full->fresh()->points_status);
    }

    public function test_orders_without_points_get_no_reversal_row(): void
    {
        Setting::query()->update(['spend_x' => 0]);
        $order = $this->completedOrder();
        $this->assertSame(0, (int) $order->points_earned);

        $this->accept($this->requestReturn($order, 50))->assertSessionHas('success');

        $this->assertSame(0, LoyaltyPointReversal::count());
    }

    public function test_point_reversals_cannot_be_edited_or_deleted(): void
    {
        $order = $this->completedOrder();
        $this->accept($this->requestReturn($order, 50));
        $reversal = LoyaltyPointReversal::sole();

        $this->expectException(LogicException::class);
        $reversal->update(['points' => 0]);
    }

    public function test_a_failure_part_way_rolls_back_the_whole_acceptance(): void
    {
        $order = $this->completedOrder();
        $return = $this->requestReturn($order, 50);
        LoyaltyPointReversal::creating(fn () => throw new RuntimeException('simulated failure'));

        $this->withoutExceptionHandling();
        try {
            $this->accept($return, ['quantities' => [$this->line($order)->id => 1]]);
            $this->fail('The simulated failure did not happen');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        $this->assertSame('pending', $return->fresh()->status);
        $this->assertNull($return->fresh()->approved_amount);
        $this->assertSame(0, ProductSize::sole()->quarantined_stock);
        $this->assertSame(0, StockMovement::where('type', StockMovement::TYPE_RETURN_QUARANTINE)->count());
        $this->assertSame(0, \App\Models\ReturnRequestItem::count());
    }
}
