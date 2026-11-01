<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment methods as data, payment status, the order snapshot (address, country, currency,
 * subtotal, discount), lifecycle timestamps and the order status history.
 *
 * Backfills are best-effort and never change an order's money: shipping_address is copied from
 * the address the order points at today (the only data available for old orders).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 50);
            $table->string('name_ar');
            $table->string('name_en');
            $table->boolean('requires_receipt')->default(false);
            $table->string('account_name')->nullable();
            $table->string('account_number', 64)->nullable();
            $table->text('instructions_ar')->nullable();
            $table->text('instructions_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['code', 'country_id']);
        });

        $now = now();
        $countryId = DB::table('countries')->where('is_default', true)->value('id');
        $settings = Schema::hasTable('settings') ? DB::table('settings')->orderBy('id')->first() : null;

        DB::table('payment_methods')->insert([
            ['country_id' => $countryId, 'code' => 'cash', 'name_ar' => 'الدفع عند الاستلام', 'name_en' => 'Cash on delivery', 'requires_receipt' => false, 'account_number' => null, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['country_id' => $countryId, 'code' => 'zaincash', 'name_ar' => 'زين كاش', 'name_en' => 'Zain Cash', 'requires_receipt' => true, 'account_number' => $settings->zaincash ?? null, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['country_id' => $countryId, 'code' => 'qicard', 'name_ar' => 'كي كارد', 'name_en' => 'Qi Card', 'requires_receipt' => true, 'account_number' => $settings->keycard ?? null, 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('orders', function (Blueprint $table) {
            // The list of methods now lives in payment_methods; the order keeps the code it used.
            $table->string('payment_method', 50)->change();
            $table->foreignId('payment_method_id')->nullable()->after('payment_method')->constrained()->nullOnDelete();
            $table->string('payment_status', 30)->default('unpaid')->after('payment_image');
            $table->foreignId('payment_verified_by')->nullable()->after('payment_status')->constrained('admins')->nullOnDelete();
            $table->timestamp('payment_verified_at')->nullable()->after('payment_verified_by');
            $table->json('shipping_address')->nullable()->after('address_id');
            $table->foreignId('country_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->char('currency_code', 3)->nullable()->after('country_id');
            $table->decimal('subtotal', 10, 2)->nullable()->after('delivery_fee');
            $table->decimal('discount_amount', 10, 2)->nullable()->after('subtotal');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('actor_type', 20); // admin | customer | provider | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['order_id', 'created_at']);
        });

        $this->backfillOrders($countryId);
    }

    private function backfillOrders(?int $countryId): void
    {
        $currency = DB::table('countries')->where('id', $countryId)->value('currency_code');
        $methods = DB::table('payment_methods')->pluck('requires_receipt', 'code');
        $methodIds = DB::table('payment_methods')->pluck('id', 'code');

        DB::table('orders')->orderBy('id')->chunkById(500, function ($orders) use ($countryId, $currency, $methods, $methodIds) {
            $subtotals = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))
                ->groupBy('order_id')->selectRaw('order_id, SUM(total_price) as subtotal')->pluck('subtotal', 'order_id');
            $addresses = DB::table('addresses')->whereIn('id', $orders->pluck('address_id')->filter())->get()->keyBy('id');

            foreach ($orders as $order) {
                $subtotal = round((float) ($subtotals[$order->id] ?? 0), 2);
                $discount = max(0, round($subtotal + (float) $order->delivery_fee - (float) $order->total_price, 2));

                $paymentStatus = match (true) {
                    $order->status === 'completed' => 'paid',
                    $order->status === 'canceled' => 'unpaid',
                    ($methods[$order->payment_method] ?? false) && $order->payment_image => 'awaiting_verification',
                    default => 'unpaid',
                };

                $address = $order->address_id ? ($addresses[$order->address_id] ?? null) : null;

                DB::table('orders')->where('id', $order->id)->update([
                    'country_id' => $countryId,
                    'currency_code' => $currency,
                    'payment_method_id' => $methodIds[$order->payment_method] ?? null,
                    'payment_status' => $paymentStatus,
                    'subtotal' => $subtotal,
                    'discount_amount' => $discount,
                    'shipping_address' => $address ? json_encode([
                        'governorate' => $address->governorate, 'city' => $address->city, 'city_id' => $address->city_id ?? null,
                        'street' => $address->street, 'building' => $address->building, 'apartment' => $address->apartment,
                        'landmark' => $address->landmark, 'address' => $address->address, 'type' => $address->type,
                        'backfilled' => true,
                    ], JSON_UNESCAPED_UNICODE) : null,
                    'completed_at' => $order->status === 'completed' ? $order->updated_at : null,
                    'canceled_at' => $order->status === 'canceled' ? $order->updated_at : null,
                ]);

                DB::table('order_status_histories')->insert([
                    'order_id' => $order->id, 'from_status' => null, 'to_status' => $order->status,
                    'actor_type' => 'system', 'note' => 'Status at migration time', 'created_at' => $order->updated_at ?? now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropConstrainedForeignId('payment_verified_by');
            $table->dropConstrainedForeignId('country_id');
            $table->dropColumn(['payment_status', 'payment_verified_at', 'shipping_address', 'currency_code', 'subtotal', 'discount_amount', 'confirmed_at', 'completed_at', 'canceled_at']);
        });
        Schema::dropIfExists('payment_methods');
    }
};
