<?php

namespace Tests\Feature\Api;

use App\Models\LoyaltyPointReversal;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class PointsAndOrderReadsApiTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        Setting::create(['points_validity_days' => 30]);
        Sanctum::actingAs($this->customer);
    }

    private function orderWithPoints(int $points, string $pointsStatus = 'earned', string $status = 'completed', ?string $createdAt = null): Order
    {
        $order = $this->makeOrder($this->customer, $this->makeSize(), status: $status);
        $order->forceFill(['points_earned' => $points, 'points_status' => $pointsStatus, 'created_at' => $createdAt ?? now()])->save();

        return $order;
    }

    private function reverse(Order $order, int $points): void
    {
        $return = ReturnRequest::create(['user_id' => $order->user_id, 'order_id' => $order->id, 'reason' => 'damaged', 'status' => 'accepted']);

        LoyaltyPointReversal::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'return_request_id' => $return->id,
            'points' => $points,
            'refunded_total' => 10,
            'reversed_total' => $points,
            'points_status_at_reversal' => 'earned',
        ]);
    }

    public function test_valid_expired_and_consumed_points_are_split_correctly(): void
    {
        $valid = $this->orderWithPoints(10);
        $this->orderWithPoints(7, createdAt: now()->subDays(40)->toDateTimeString());
        $this->orderWithPoints(5, pointsStatus: 'redeemed');
        $this->orderWithPoints(9, status: 'canceled');
        $other = $this->makeOrder(User::factory()->create(), $this->makeSize());
        $other->forceFill(['points_earned' => 100, 'points_status' => 'earned'])->save();

        $this->getJson('/api/user/points/valid')
            ->assertOk()
            ->assertJsonPath('valid_points_total', 10)
            ->assertJsonPath('orders.0.order_id', $valid->id)
            ->assertJsonCount(1, 'orders');

        $this->getJson('/api/user/points/expired')->assertOk()->assertJsonPath('expired_points_total', 7)->assertJsonCount(1, 'orders');
        $this->getJson('/api/user/points/notvalid')->assertOk()->assertJsonPath('consumed_points_total', 5)->assertJsonCount(1, 'orders');

        $this->assertCount(4, $this->getJson('/api/user/points')->assertOk()->json('orders'));
    }

    public function test_orders_with_an_accepted_return_are_hidden_from_the_app(): void
    {
        // Orders with an accepted (even partial) return stay hidden from the order list and
        // details (decision D3, now an explicit Order::visibleToCustomer() scope).
        //
        // Points changed deliberately: the customer sees the same points as the dashboard
        // (LoyaltyService), i.e. what is left after the refund reversal. They used to be hidden,
        // while the admin could still redeem them.
        $kept = $this->orderWithPoints(10);
        $returned = $this->orderWithPoints(6);
        $this->reverse($returned, 3);

        $this->getJson('/api/user/points/valid')
            ->assertOk()
            ->assertJsonPath('valid_points_total', 13)
            ->assertJsonCount(2, 'orders');
        $this->assertSame(3, collect($this->getJson('/api/user/points/valid')->json('orders'))->firstWhere('order_id', $returned->id)['points_earned']);

        // A fully reversed order has nothing left and is not listed.
        $this->reverse($fully = $this->orderWithPoints(4), 4);
        $this->assertNotContains($fully->id, collect($this->getJson('/api/user/points/valid')->json('orders'))->pluck('order_id'));

        $this->getJson("/api/user/order-details/{$returned->id}")->assertNotFound();
        $this->assertArrayNotHasKey($returned->id, collect($this->getJson('/api/user/orders/grouped-by-status')->json('data'))->flatten(1)->keyBy('order_id'));
    }

    public function test_order_lists_and_details_only_cover_the_callers_orders(): void
    {
        $size = $this->makeSize(price: 25);
        $pending = $this->makeOrder($this->customer, $size, quantity: 2);
        $done = $this->makeOrder($this->customer, $size, status: 'completed');
        $theirs = $this->makeOrder(User::factory()->create(), $size);

        $grouped = $this->getJson('/api/user/orders/grouped-by-status')->assertOk()->json('data');
        $this->assertSame([$pending->id], array_column($grouped['pending'], 'order_id'));
        $this->assertSame([$done->id], array_column($grouped['completed'], 'order_id'));
        $this->assertEquals(2, $grouped['pending'][0]['items_count']);

        $this->getJson("/api/user/order-details/{$pending->id}")
            ->assertOk()
            ->assertJsonPath('order_id', $pending->id)
            ->assertJsonPath('products.0.quantity', 2)
            ->assertJsonMissingPath('user');

        $this->getJson("/api/user/track-order/{$done->id}")->assertOk()->assertJsonPath('status', 'completed');

        $this->getJson("/api/user/order-details/{$theirs->id}")->assertNotFound();
        $this->getJson("/api/user/track-order/{$theirs->id}")->assertNotFound();
        $this->getJson('/api/user/order-details/999999')->assertNotFound();
    }

    public function test_return_reads_and_cancel_are_limited_to_the_caller(): void
    {
        $order = $this->makeOrder($this->customer, $this->makeSize(), status: 'completed');
        $mine = ReturnRequest::create(['user_id' => $this->customer->id, 'order_id' => $order->id, 'reason' => 'damaged']);
        $done = ReturnRequest::create(['user_id' => $this->customer->id, 'order_id' => $order->id, 'reason' => 'damaged', 'status' => 'accepted']);
        $otherUser = User::factory()->create();
        $theirs = ReturnRequest::create([
            'user_id' => $otherUser->id,
            'order_id' => $this->makeOrder($otherUser, $this->makeSize(), status: 'completed')->id,
            'reason' => 'damaged',
        ]);

        $this->assertEqualsCanonicalizing([$mine->id, $done->id], array_column($this->getJson('/api/user/returns')->assertOk()->json(), 'id'));
        $this->getJson("/api/user/returns/{$mine->id}")->assertOk()->assertJsonPath('id', $mine->id);
        $this->getJson("/api/user/returns/{$theirs->id}")->assertNotFound();

        $this->postJson("/api/user/returns/{$theirs->id}/cancel")->assertNotFound();
        $this->postJson("/api/user/returns/{$done->id}/cancel")->assertStatus(400);
        $this->postJson("/api/user/returns/{$mine->id}/cancel")->assertOk();
        $this->postJson("/api/user/returns/{$mine->id}/cancel")->assertStatus(400);

        $this->assertSame('canceled', $mine->fresh()->status);
        $this->assertSame('accepted', $done->fresh()->status);
        $this->assertSame('pending', $theirs->fresh()->status);
    }

    public function test_cart_line_of_another_user_cannot_be_removed(): void
    {
        $line = $this->putInCart(User::factory()->create(), $this->makeSize(), 1);

        $this->deleteJson("/api/user/cart/{$line->id}")->assertNotFound();
        $this->assertNotNull($line->fresh());

        $mine = $this->putInCart($this->customer, $this->makeSize(), 1);
        $this->deleteJson("/api/user/cart/{$mine->id}")->assertOk();
        $this->assertNull($mine->fresh());
    }
}
