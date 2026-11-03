<?php

namespace Database\Seeders\Demo;

use App\Models\Admin;
use App\Models\LoyaltyReward;
use App\Models\ProductSize;
use App\Models\User;
use App\Modules\Points\Services\LoyaltyService;
use App\Modules\Points\Services\RewardRedemptionService;
use Illuminate\Database\Seeder;

/**
 * Loyalty demo data, written through the services like the dashboard would: a rewards catalog
 * (a product, free delivery, a voucher), a welcome bonus for each demo customer, and a few
 * redemptions in different states.
 */
class LoyaltySeeder extends Seeder
{
    public function run(): void
    {
        $admin = Admin::query()->first(); // null when no admin is seeded: recorded as system actions
        $size = ProductSize::whereHas('product')->where('stock', '>', 5)->orderBy('id')->first();

        $rewards = collect([
            $size ? ['code' => 'RW-PRODUCT', 'type' => LoyaltyReward::TYPE_PRODUCT, 'product_id' => $size->product_id, 'product_size_id' => $size->id,
                'title' => ($size->product->name_en ?? 'Product').' '.$size->size, 'points_required' => 100, 'stock' => 20, 'per_customer_limit' => 2, 'sort_order' => 1] : null,
            ['code' => 'RW-FREE-DELIVERY', 'type' => LoyaltyReward::TYPE_FREE_DELIVERY, 'title' => 'Free delivery', 'points_required' => 150, 'stock' => null, 'sort_order' => 2,
                'terms' => 'One delivery inside the covered zones.'],
            ['code' => 'RW-VOUCHER-50', 'type' => LoyaltyReward::TYPE_VOUCHER, 'title' => '50 EGP voucher', 'points_required' => 250, 'stock' => 50, 'total_limit' => 50, 'sort_order' => 3],
        ])->filter()->map(fn (array $attributes) => LoyaltyReward::firstOrCreate(['code' => $attributes['code']], $attributes + ['is_active' => true]));

        $loyalty = app(LoyaltyService::class);
        $redemptions = app(RewardRedemptionService::class);

        foreach (User::whereIn('email', array_keys(CustomerSeeder::CUSTOMERS))->orderBy('id')->get() as $i => $customer) {
            $loyalty->adjust($customer, 300, 'Welcome bonus', $admin, 'bonus', null, "demo-welcome-{$customer->id}");

            $reward = $rewards->values()[$i % $rewards->count()];
            $redemption = $redemptions->redeem($customer, $reward, 1, $admin, "demo-{$customer->id}");
            match ($i % 3) {
                0 => $redemptions->fulfil($redemption, $admin),
                1 => $redemptions->cancel($redemption, $admin, 'Customer changed their mind'),
                default => null, // left pending, waiting to be collected
            };
        }
    }
}
