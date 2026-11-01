<?php

namespace Tests\Feature\Marketing;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductSize;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Modules\Orders\Services\OrderWorkflow;
use App\Modules\Orders\Services\ReturnDecisionService;
use App\Modules\Promotions\Services\PromotionRefundPolicy;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPromotions;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Returns and refunds of promotional orders refund what was paid, and no more. */
class PromotionReturnsTest extends TestCase
{
    use RefreshDatabase, BuildsShop, BuildsPromotions;

    private User $user;
    private ProductSize $bought;
    private ProductSize $gift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->bought = $this->makeSize(price: 50, stock: 20);
        $this->gift = $this->makeSize(price: 30, stock: 10);
    }

    private function completedGiftOrder(): Order
    {
        $this->buyXGetY($this->bought, $this->gift);
        $this->add($this->bought, 2);
        $order = Order::find($this->checkout()->assertCreated()->json('order_id'));
        app(OrderWorkflow::class)->transition($order, OrderStatus::Completed, OrderWorkflow::ACTOR_SYSTEM);

        return $order->fresh('items');
    }

    private function returnOf(Order $order, float $amount): ReturnRequest
    {
        return ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'reason' => 'x', 'refund_amount' => $amount]);
    }

    public function test_returning_a_qualifying_item_but_keeping_the_gift_deducts_the_gift_value(): void
    {
        $order = $this->completedGiftOrder();
        $paid = $order->items->firstWhere('is_gift', false);

        // One of the two qualifying units back: the gift is no longer earned (worth 30).
        $quote = app(PromotionRefundPolicy::class)->quote($order, [$paid->id => 1]);
        $this->assertSame(['max' => 2000, 'returned_value' => 5000, 'deduction' => 3000, 'delivery' => 0], $quote);

        $this->expectException(DomainException::class);
        app(ReturnDecisionService::class)->accept($this->returnOf($order, 50)->id, 50, [$paid->id => 1], null);
    }

    public function test_returning_the_gift_too_refunds_the_full_paid_unit_and_the_gift_refunds_nothing(): void
    {
        $order = $this->completedGiftOrder();
        $paid = $order->items->firstWhere('is_gift', false);
        $gift = $order->items->firstWhere('is_gift', true);

        $accepted = app(ReturnDecisionService::class)->accept($this->returnOf($order, 50)->id, 50, [$paid->id => 1, $gift->id => 1], null);

        $this->assertSame('accepted', $accepted->status);
        $this->assertSame(OrderItem::GIFT_RETURNED, $gift->fresh()->gift_status);
        // The returned gift goes to quarantine like any returned unit: it is not sellable again.
        $this->assertSame(1, (int) $this->gift->fresh()->quarantined_stock);
        $this->assertSame(9, $this->gift->fresh()->stock);
    }

    public function test_returning_only_the_gift_refunds_nothing(): void
    {
        $order = $this->completedGiftOrder();
        $gift = $order->items->firstWhere('is_gift', true);

        $this->assertSame(0, app(PromotionRefundPolicy::class)->quote($order, [$gift->id => 1])['max']);
        $this->expectException(DomainException::class);
        app(ReturnDecisionService::class)->accept($this->returnOf($order, 1)->id, 1, [$gift->id => 1], null);
    }

    public function test_order_level_discounts_reduce_what_each_returned_unit_refunds(): void
    {
        $other = $this->makeSize(price: 100, stock: 10);
        $coupon = $this->couponCode(['discount_value' => 10]);
        $this->add($other, 2); // 200 − 10% = 180 paid
        $order = Order::find($this->checkout(['coupon_code' => $coupon->code])->assertCreated()->json('order_id'));
        app(OrderWorkflow::class)->transition($order, OrderStatus::Completed, OrderWorkflow::ACTOR_SYSTEM);
        $line = $order->items->first();

        $this->assertSame(9000, app(PromotionRefundPolicy::class)->quote($order->fresh('items'), [$line->id => 1])['max']);
        app(ReturnDecisionService::class)->accept($this->returnOf($order, 90)->id, 90, [$line->id => 1], null);

        // The second unit: the remaining 90, never more than was paid in total.
        $second = $this->returnOf($order, 100);
        $this->expectException(DomainException::class);
        app(ReturnDecisionService::class)->accept($second->id, 100, [$line->id => 1], null);
    }

    public function test_a_spend_gift_lost_by_a_partial_return_is_deducted_once(): void
    {
        $item = $this->makeSize(price: 300, stock: 10);
        $this->spendXGetY([500 => $this->gift]);
        $this->add($item, 2); // 600 ≥ 500 → gift worth 30
        $order = Order::find($this->checkout()->assertCreated()->json('order_id'));
        app(OrderWorkflow::class)->transition($order, OrderStatus::Completed, OrderWorkflow::ACTOR_SYSTEM);
        $order->load('items');
        $line = $order->items->firstWhere('is_gift', false);

        $first = app(PromotionRefundPolicy::class)->quote($order, [$line->id => 1]);
        $this->assertSame(['max' => 27000, 'returned_value' => 30000, 'deduction' => 3000, 'delivery' => 0], $first);
        app(ReturnDecisionService::class)->accept($this->returnOf($order, 270)->id, 270, [$line->id => 1], null);

        // The second unit: the gift was already deducted.
        $this->assertSame(30000, app(PromotionRefundPolicy::class)->quote($order->fresh('items'), [$line->id => 1])['max']);
    }
}
