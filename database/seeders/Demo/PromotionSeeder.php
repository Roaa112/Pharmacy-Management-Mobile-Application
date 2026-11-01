<?php

namespace Database\Seeders\Demo;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\DiscountRule;
use App\Models\DiscountRuleTarget;
use App\Models\FlashSale;
use App\Models\Product;
use App\Support\StoreClock;
use Illuminate\Database\Seeder;

/**
 * Discount rules (price, buy-x-get-y and gift rules), flash sales and coupons. Dates are the
 * store's wall-clock time, like the admin enters them (see StoreClock).
 */
class PromotionSeeder extends Seeder
{
    public function run(): void
    {
        $now = StoreClock::now();
        $product = fn (string $nameEn) => Product::where('name_en', $nameEn)->firstOrFail();

        // 10% off all vitamins and supplements for the next three weeks.
        $this->rule(
            ['discount_type' => 'percent', 'discount_value' => 10, 'starts_at' => $now->copy()->subDays(7), 'ends_at' => $now->copy()->addDays(21)],
            [['category', Category::where('name_en', 'Vitamins & Supplements')->value('id')]]
        );

        // 25 off the moisturizing cream.
        $this->rule(
            ['discount_type' => 'fixed', 'discount_value' => 25, 'starts_at' => $now->copy()->subDays(2), 'ends_at' => $now->copy()->addDays(10)],
            [['product', $product('Intense Moisturizing Cream')->id]]
        );

        // An ended rule, to show that expired rules no longer change prices.
        $this->rule(
            ['discount_type' => 'percent', 'discount_value' => 20, 'starts_at' => $now->copy()->subDays(60), 'ends_at' => $now->copy()->subDays(30)],
            [['brand', Brand::where('name_en', 'DermaCare')->value('id')]]
        );

        // Buy 2 baby shampoos, get a diaper cream as a gift.
        $this->rule(
            ['discount_type' => 'buy_x_get_y', 'min_quantity' => 2, 'free_quantity' => 1, 'starts_at' => $now->copy()->subDays(3), 'ends_at' => $now->copy()->addDays(30)],
            [['product', $product('No-Tears Baby Shampoo')->id]],
            ['product', $product('Baby Diaper Cream')->id]
        );

        // Spend 1000 on medical devices, get a pack of test strips as a gift.
        $this->rule(
            ['discount_type' => 'amount_gift', 'min_amount' => 1000, 'starts_at' => $now->copy()->subDays(3), 'ends_at' => $now->copy()->addDays(30)],
            [['category', Category::where('name_en', 'Medical Devices')->value('id')]],
            ['product', $product('Glucose Test Strips')->id]
        );

        // A flash sale that is live now (one hour) and one tomorrow evening.
        $this->flashSale($product('Paracetamol 500 mg'), 15, $now->copy()->subMinutes(10));
        $this->flashSale($product('Sunscreen SPF 50+'), 30, $now->copy()->addDay()->setTime(20, 0));

        $this->coupons($now);
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $targets  [type, id] the rule applies to
     * @param  array{0: string, 1: int}|null  $gift  [type, id] given for buy_x_get_y / amount_gift
     */
    private function rule(array $data, array $targets, ?array $gift = null): void
    {
        $rule = DiscountRule::create($data + [
            'applies_to_type' => $targets[0][0],
            'applies_to_id' => $targets[0][1],
            'gift_type' => $gift[0] ?? null,
            'gift_id' => $gift[1] ?? null,
            'gift_quantity' => $gift ? 1 : null,
        ]);

        foreach ($targets as [$type, $id]) {
            DiscountRuleTarget::create(['discount_rule_id' => $rule->id, 'target_type' => $type, 'target_id' => $id, 'is_gift' => false]);
        }

        if ($gift) {
            DiscountRuleTarget::create(['discount_rule_id' => $rule->id, 'target_type' => $gift[0], 'target_id' => $gift[1], 'is_gift' => true]);
        }
    }

    private function flashSale(Product $product, float $discount, $startsAt): void
    {
        $sale = FlashSale::create([
            'discount_value' => $discount,
            'is_active' => true,
            'date' => $startsAt->toDateString(),
            'time' => $startsAt->format('H:i:s'),
            'ends_at' => $startsAt->copy()->addHour(),
        ]);

        $product->forceFill(['saleable_type' => FlashSale::class, 'saleable_id' => $sale->id])->save();
    }

    private function coupons($now): void
    {
        Coupon::create([
            'code' => 'WELCOME10', 'discount_value' => 10, 'is_active' => true,
            'start_at' => $now->copy()->subDays(30), 'end_at' => $now->copy()->addDays(60),
            'used_count' => 0, 'usage_limit' => 500, 'once_per_user' => true,
        ]);

        Coupon::create([
            'code' => 'HEALTH20', 'discount_value' => 20, 'is_active' => true,
            'start_at' => $now->copy()->subDays(5), 'end_at' => $now->copy()->addDays(5),
            'used_count' => 0, 'usage_limit' => 50, 'once_per_user' => false,
        ]);

        // Expired, inactive and used-up coupons for the admin list and the rejection paths.
        Coupon::create([
            'code' => 'SUMMER25', 'discount_value' => 25, 'is_active' => true,
            'start_at' => $now->copy()->subDays(120), 'end_at' => $now->copy()->subDays(60),
            'used_count' => 0, 'usage_limit' => 100, 'once_per_user' => true,
        ]);

        Coupon::create([
            'code' => 'PAUSED15', 'discount_value' => 15, 'is_active' => false,
            'start_at' => $now->copy()->subDays(10), 'end_at' => $now->copy()->addDays(20),
            'used_count' => 0, 'usage_limit' => 100, 'once_per_user' => true,
        ]);

        Coupon::create([
            'code' => 'VIP50', 'discount_value' => 50, 'is_active' => true,
            'start_at' => $now->copy()->subDays(10), 'end_at' => $now->copy()->addDays(20),
            'used_count' => 0, 'usage_limit' => 1, 'once_per_user' => true,
        ]);
    }
}
