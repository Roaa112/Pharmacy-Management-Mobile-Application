<?php

namespace Tests\Concerns;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\DiscountRule;
use App\Models\FlashSale;
use App\Models\ProductSize;
use App\Support\StoreClock;
use Illuminate\Testing\TestResponse;

/** Promotions and cart calls for the marketing tests (requires BuildsShop and an acting user). */
trait BuildsPromotions
{
    /**
     * @param  list<array{0: string, 1: int, 2?: bool}>  $targets  [type, id, excluded]
     * @param  list<array>  $rewards  promotion_rewards attributes
     */
    protected function promotion(string $type, array $attributes = [], array $targets = [], array $rewards = []): DiscountRule
    {
        $promotion = DiscountRule::create($attributes + [
            'discount_type' => $type,
            'name_en' => ucfirst(str_replace('_', ' ', $type)),
            'starts_at' => StoreClock::now()->subDay(),
            'ends_at' => StoreClock::now()->addDay(),
            'is_active' => true,
        ]);

        foreach ($targets as $target) {
            $promotion->targets()->create(['target_type' => $target[0], 'target_id' => $target[1], 'is_excluded' => $target[2] ?? false]);
        }
        foreach ($rewards as $i => $reward) {
            $promotion->rewards()->create($reward + ['quantity' => 1, 'sort_order' => $i]);
        }

        return $promotion->fresh(['targets', 'rewards']);
    }

    /** Buy $buy of $bought, get $gift free ($quantity per qualification). */
    protected function buyXGetY(ProductSize $bought, ProductSize $gift, int $buy = 2, int $quantity = 1, array $attributes = []): DiscountRule
    {
        return $this->promotion(DiscountRule::TYPE_BUY_X_GET_Y, $attributes + ['min_quantity' => $buy],
            [['product', $bought->product_id]],
            [['reward_type' => 'free_product', 'product_id' => $gift->product_id, 'product_size_id' => $gift->id, 'quantity' => $quantity]]);
    }

    /** @param  array<int|string, ProductSize>  $tiers  threshold => gift */
    protected function spendXGetY(array $tiers, array $attributes = [], array $targets = []): DiscountRule
    {
        $rewards = [];
        foreach ($tiers as $threshold => $gift) {
            $rewards[] = ['reward_type' => 'free_product', 'threshold_amount' => $threshold, 'product_id' => $gift->product_id, 'product_size_id' => $gift->id];
        }

        return $this->promotion(DiscountRule::TYPE_SPEND_X_GET_Y, $attributes, $targets, $rewards);
    }

    protected function flashSaleOn(ProductSize $size, float $percent = 20, array $attributes = []): FlashSale
    {
        $start = StoreClock::now()->subHour();
        $sale = FlashSale::create($attributes + ['discount_value' => $percent, 'is_active' => true, 'date' => $start->toDateString(), 'time' => $start->format('H:i:s'), 'ends_at' => $start->copy()->addHours(5)]);
        $sale->items()->create(['product_id' => $size->product_id]);

        return $sale;
    }

    protected function couponCode(array $attributes = []): Coupon
    {
        return Coupon::create($attributes + ['code' => 'C'.uniqid(), 'discount_value' => 10, 'is_active' => true, 'start_at' => now()->subDay(), 'end_at' => now()->addDay()]);
    }

    protected function add(ProductSize $size, int $quantity): TestResponse
    {
        return $this->postJson('/api/user/cart', ['product_id' => $size->product_id, 'product_size' => $size->id, 'quantity' => $quantity])->assertOk();
    }

    protected function setQuantity(ProductSize $size, int $quantity): void
    {
        $line = Cart::customerLines()->where('product_size_id', $size->id)->firstOrFail();
        while ($line->fresh()->product_quantity > $quantity) {
            $this->postJson('/api/user/cart/update-quantity', ['product_id' => $size->product_id, 'old_size_id' => $size->id, 'operation' => 'decrement'])->assertOk();
        }
        while ($line->fresh()->product_quantity < $quantity) {
            $this->postJson('/api/user/cart/update-quantity', ['product_id' => $size->product_id, 'old_size_id' => $size->id, 'operation' => 'increment'])->assertOk();
        }
    }

    protected function cart(array $query = []): TestResponse
    {
        return $this->getJson('/api/user/cart'.($query ? '?'.http_build_query($query) : ''))->assertOk();
    }

    /** Gift lines in the cart table, keyed by product size id => quantity. */
    protected function giftLines(): array
    {
        return Cart::giftLines()->orderBy('product_size_id')->pluck('product_quantity', 'product_size_id')->map(fn ($q) => (int) $q)->all();
    }

    protected function checkout(array $data = []): TestResponse
    {
        return $this->postJson('/api/user/orders', $data + ['address_id' => -1, 'payment_method' => 'cash']);
    }
}
