<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The marketing domain behind the promotion engine (App\Modules\Promotions):
 *
 *  - discount_rules become full promotions (name, status, priority, exclusivity, stacking,
 *    repetition, limits, customer/country scope, qualifying-spend options, campaign) with
 *    explicit rewards (promotion_rewards) and tiers, excluded targets and variant targets;
 *  - campaigns group promotions under shared dates and eligibility;
 *  - flash sales get products/variants (flash_sale_items), fixed or percent, limits, a name;
 *  - coupons get product/category/customer scope, first-order-only, country, and a
 *    reservation lifecycle (coupon_redemptions);
 *  - cart rows can be promotional gifts; order lines and orders keep promotion snapshots
 *    (order_promotions, pricing_snapshot);
 *  - banners and opening ads get scheduling, targeting and bilingual content.
 *
 * Every new column defaults to the behaviour the code had before, so live promotions keep
 * working the same way until an admin changes them. No existing value is rewritten except
 * the backfills described inline (all additive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_scope', 20)->default('all'); // all | new | returning
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('discount_rules', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('id');
            $table->string('name_ar')->nullable()->after('name_en');
            $table->text('description_en')->nullable()->after('name_ar');
            $table->text('description_ar')->nullable()->after('description_en');
            $table->foreignId('campaign_id')->nullable()->after('description_ar')->constrained('promotion_campaigns')->nullOnDelete();
            // Existing rules were live whenever inside their dates.
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->boolean('is_exclusive')->default(false);
            $table->boolean('is_stackable')->default(false);
            $table->boolean('repeatable')->default(false);
            $table->unsignedInteger('max_applications_per_order')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->string('customer_scope', 20)->default('all'); // all | new | returning | specific
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('min_order_amount', 10, 2)->nullable();
            $table->decimal('max_order_amount', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->string('qualifying_basis', 12)->default('discounted'); // discounted | original
            $table->boolean('count_delivery_fee')->default(false);
            $table->boolean('coupon_reduces_qualifying')->default(true);
            $table->string('tier_mode', 12)->default('highest'); // highest | cumulative
            $table->string('gift_fallback', 12)->default('partial'); // partial | skip | block
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->softDeletes();
        });

        // free_delivery joins the types; a string keeps future types a code change only.
        Schema::table('discount_rules', function (Blueprint $table) {
            $table->string('discount_type', 30)->change();
            $table->string('applies_to_type')->nullable()->change();
            $table->unsignedBigInteger('applies_to_id')->nullable()->change();
        });

        Schema::table('discount_rule_targets', function (Blueprint $table) {
            // An excluded target removes matching items from an otherwise broader rule.
            $table->boolean('is_excluded')->default(false)->after('is_gift');
        });

        Schema::create('promotion_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_rule_id')->constrained()->cascadeOnDelete();
            // Spend X tiers: the qualifying amount this reward needs (null = the rule's min_amount).
            $table->decimal('threshold_amount', 10, 2)->nullable();
            $table->string('reward_type', 20); // free_product | fixed_discount | percent_discount | free_delivery
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            // null = the product's first size (id order) that has the stock.
            $table->foreignId('product_size_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('value', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('promotion_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['discount_rule_id', 'user_id']);
        });

        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_rule_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12); // reserved | consumed | released
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->unsignedInteger('reward_quantity')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['discount_rule_id', 'order_id']);
            $table->index(['discount_rule_id', 'user_id', 'status']);
        });

        Schema::table('flash_sales', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('id');
            $table->string('name_ar')->nullable()->after('name_en');
            $table->string('discount_type', 10)->default('percent')->after('name_ar'); // percent | fixed
            $table->integer('priority')->default(0);
            $table->unsignedInteger('quantity_limit')->nullable(); // units at the flash price, all customers
            $table->unsignedInteger('per_customer_limit')->nullable(); // units per customer
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('flash_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_size_id')->nullable()->constrained()->cascadeOnDelete(); // null = every size
            $table->unsignedInteger('quantity_limit')->nullable();
            // Created from products.saleable (the product form's link); kept in sync with it.
            $table->boolean('is_product_link')->default(false);
            $table->timestamps();
            $table->unique(['flash_sale_id', 'product_id', 'product_size_id'], 'flash_sale_items_unique');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->string('name')->nullable()->after('code');
            $table->boolean('first_order_only')->default(false);
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            // Whether the coupon also applies to items already reduced by a promotion, and next to
            // automatic order discounts. Off for new coupons (no stacking by default).
            $table->boolean('combines_with_promotions')->default(false);
            $table->string('customer_scope', 20)->default('all'); // all | specific
            $table->softDeletes();
        });

        Schema::create('coupon_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 20); // product | variant | brand | category
            $table->unsignedBigInteger('target_id');
            $table->boolean('is_excluded')->default(false);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('coupon_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['coupon_id', 'user_id']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code');
            $table->string('status', 12); // reserved | consumed | released
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['coupon_id', 'user_id', 'status']);
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->boolean('is_gift')->default(false);
            $table->foreignId('discount_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('promotion_reward_id')->nullable()->constrained()->nullOnDelete();
            // promotion:reward — one gift line per reward per customer; written by CartPromotionSync only.
            $table->string('gift_key', 64)->nullable();
            $table->unique(['user_id', 'gift_key']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('promotion_reward_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flash_sale_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('discount_amount', 10, 2)->default(0); // (original − price) × quantity
            $table->string('gift_status', 12)->nullable(); // granted | returned
            $table->json('promotion_snapshot')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('items_discount_amount', 10, 2)->default(0);
            $table->decimal('coupon_discount_amount', 10, 2)->default(0);
            $table->decimal('promotion_discount_amount', 10, 2)->default(0);
            $table->decimal('delivery_discount_amount', 10, 2)->default(0);
            $table->decimal('total_savings', 10, 2)->default(0);
            $table->string('coupon_code')->nullable();
            $table->longText('pricing_snapshot')->nullable();
        });

        Schema::create('order_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('source', 20); // promotion | flash_sale | coupon
            $table->foreignId('discount_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flash_sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('promotion_type', 30);
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->decimal('eligible_amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('delivery_discount_amount', 10, 2)->default(0);
            $table->unsignedInteger('reward_quantity')->default(0);
            $table->json('details')->nullable();
            $table->timestamps();
        });

        Schema::table('banners', function (Blueprint $table) {
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('position', 30)->default('home');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device', 10)->default('all'); // all | ios | android | web
        });

        Schema::table('opening_ads', function (Blueprint $table) {
            $table->string('title_en')->nullable();
            $table->string('title_ar')->nullable();
            $table->text('content_en')->nullable();
            $table->text('content_ar')->nullable();
            $table->string('link')->nullable();
            $table->string('display_frequency', 20)->default('every_open'); // every_open | once_per_session | once_per_day | once
            $table->integer('priority')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device', 10)->default('all');
        });

        Schema::table('settings', function (Blueprint $table) {
            // Decision D5: a canceled order gives its coupon use back.
            $table->boolean('coupon_release_on_cancel')->default(true);
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        // Existing coupons keep applying on top of promotion prices, as customers were promised.
        DB::table('coupons')->update(['combines_with_promotions' => true]);

        // Flash sales saved without an end: the one hour they always had becomes explicit.
        foreach (DB::table('flash_sales')->whereNull('ends_at')->get(['id', 'date', 'time']) as $sale) {
            if ($sale->date && $sale->time) {
                DB::table('flash_sales')->where('id', $sale->id)->update([
                    'ends_at' => \Illuminate\Support\Carbon::parse(substr((string) $sale->date, 0, 10).' '.$sale->time)->addHour()->toDateTimeString(),
                ]);
            }
        }

        // The product form's flash sale link becomes a flash sale item.
        $now = now();
        DB::table('products')->where('saleable_type', 'App\\Models\\FlashSale')->whereNotNull('saleable_id')->orderBy('id')
            ->chunkById(500, function ($products) use ($now) {
                $existing = DB::table('flash_sales')->pluck('id')->flip();
                $rows = [];
                foreach ($products as $product) {
                    if (isset($existing[$product->saleable_id])) {
                        $rows[] = ['flash_sale_id' => $product->saleable_id, 'product_id' => $product->id, 'product_size_id' => null,
                            'is_product_link' => true, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
                DB::table('flash_sale_items')->insert($rows);
            });

        // Product gift targets become explicit free-product rewards (the old resolver gave the
        // first size in stock; product_size_id null keeps that). Brand/category gift targets stay
        // description-only (decision D12) and show up as configuration errors in the dashboard.
        $rules = DB::table('discount_rules')->whereIn('discount_type', ['buy_x_get_y', 'amount_gift'])->get();
        foreach ($rules as $rule) {
            $gift = DB::table('discount_rule_targets')->where('discount_rule_id', $rule->id)->where('is_gift', true)->where('target_type', 'product')->orderBy('id')->first();
            if ($gift && DB::table('products')->where('id', $gift->target_id)->exists()) {
                DB::table('promotion_rewards')->insert([
                    'discount_rule_id' => $rule->id,
                    'threshold_amount' => $rule->discount_type === 'amount_gift' ? $rule->min_amount : null,
                    'reward_type' => 'free_product',
                    'product_id' => $gift->target_id,
                    'quantity' => max(1, (int) ($rule->gift_quantity ?: $rule->free_quantity ?: 1)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Coupon uses already recorded become redemptions. Canceled orders kept their use (the
        // old behaviour, decision D5), so they are "consumed" too; counts stay exactly the same.
        DB::table('orders')->whereNotNull('coupon_id')->orderBy('id')->chunkById(500, function ($orders) use ($now) {
            $codes = DB::table('coupons')->pluck('code', 'id');
            $rows = [];
            foreach ($orders as $order) {
                if (!isset($codes[$order->coupon_id])) {
                    continue;
                }
                $rows[] = ['coupon_id' => $order->coupon_id, 'order_id' => $order->id, 'user_id' => $order->user_id,
                    'code' => $codes[$order->coupon_id], 'status' => 'consumed', 'discount_amount' => $order->discount_amount ?? 0,
                    'reserved_at' => $order->created_at, 'consumed_at' => $order->created_at, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('coupon_redemptions')->insert($rows);
        });

        DB::table('orders')->whereNotNull('coupon_id')->update(['coupon_discount_amount' => DB::raw('COALESCE(discount_amount, 0)')]);
    }

    public function down(): void
    {
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn('coupon_release_on_cancel'));

        Schema::table('opening_ads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('country_id');
            $table->dropColumn(['title_en', 'title_ar', 'content_en', 'content_ar', 'link', 'display_frequency', 'priority', 'starts_at', 'ends_at', 'device']);
        });

        Schema::table('banners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('country_id');
            $table->dropColumn(['description_en', 'description_ar', 'position', 'starts_at', 'ends_at', 'device']);
        });

        Schema::dropIfExists('order_promotions');

        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn([
            'items_discount_amount', 'coupon_discount_amount', 'promotion_discount_amount', 'delivery_discount_amount',
            'total_savings', 'coupon_code', 'pricing_snapshot',
        ]));

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_reward_id');
            $table->dropConstrainedForeignId('flash_sale_id');
            $table->dropColumn(['discount_amount', 'gift_status', 'promotion_snapshot']);
        });

        if (!Schema::hasIndex('carts', 'carts_user_id_fk_index')) {
            Schema::table('carts', function (Blueprint $table) {
                // MySQL may use the (user_id, gift_key) index for the carts.user_id foreign key: give
                // the foreign key its own index before dropping the composite one.
                $table->index('user_id', 'carts_user_id_fk_index');
            });
        }
        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'gift_key']);
            $table->dropConstrainedForeignId('discount_rule_id');
            $table->dropConstrainedForeignId('promotion_reward_id');
            $table->dropColumn(['is_gift', 'gift_key']);
        });

        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupon_customers');
        Schema::dropIfExists('coupon_targets');

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('country_id');
            $table->dropColumn(['name', 'first_order_only', 'combines_with_promotions', 'customer_scope', 'deleted_at']);
        });

        Schema::dropIfExists('flash_sale_items');

        Schema::table('flash_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('country_id');
            $table->dropColumn(['name_en', 'name_ar', 'discount_type', 'priority', 'quantity_limit', 'per_customer_limit']);
        });

        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('promotion_customers');
        Schema::dropIfExists('promotion_rewards');

        Schema::table('discount_rule_targets', fn (Blueprint $table) => $table->dropColumn('is_excluded'));

        Schema::table('discount_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropConstrainedForeignId('country_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn([
                'name_en', 'name_ar', 'description_en', 'description_ar', 'is_active', 'priority', 'is_exclusive',
                'is_stackable', 'repeatable', 'max_applications_per_order', 'usage_limit', 'per_customer_limit',
                'customer_scope', 'min_order_amount', 'max_order_amount', 'max_discount_amount', 'qualifying_basis',
                'count_delivery_fee', 'coupon_reduces_qualifying', 'tier_mode', 'gift_fallback', 'deleted_at',
            ]);
        });

        // discount_type stays a string: free_delivery rows could not go back into the old enum.

        Schema::dropIfExists('promotion_campaigns');
    }
};
