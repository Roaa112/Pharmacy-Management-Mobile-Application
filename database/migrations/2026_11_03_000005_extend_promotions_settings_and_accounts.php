<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupon attributes, flash sale end time, gift order lines, typed store policies on the settings
 * singleton, account activation for customers and roles for admins. Every new column defaults to
 * the behaviour the code had before, so nothing changes until an admin changes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('type', 10)->default('percent')->after('code'); // percent | fixed
            $table->decimal('min_order_amount', 10, 2)->nullable()->after('discount_value');
            $table->decimal('max_discount_amount', 10, 2)->nullable()->after('min_order_amount');
            $table->unsignedInteger('per_user_limit')->nullable()->after('once_per_user');
            // null = follow settings.coupon_applies_to_delivery
            $table->boolean('applies_to_delivery')->nullable()->after('per_user_limit');
        });

        Schema::table('flash_sales', function (Blueprint $table) {
            // null = the historical one hour from date + time.
            $table->dateTime('ends_at')->nullable()->after('time');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('is_gift')->default(false)->after('total_price');
            $table->foreignId('discount_rule_id')->nullable()->after('is_gift')->constrained()->nullOnDelete();
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedInteger('low_stock_threshold')->nullable();
            $table->unsignedInteger('return_window_days')->nullable();
            $table->boolean('customer_can_cancel_pending')->default(false);
            $table->boolean('coupon_applies_to_delivery')->default(true);
            $table->boolean('vaccine_reminders_enabled')->default(true);
            $table->boolean('medication_reminders_enabled')->default(false);
        });

        if (!Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('is_verified');
            });
        }

        Schema::table('admins', function (Blueprint $table) {
            // Existing admins keep full access.
            $table->string('role', 30)->default('super_admin')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });

        Schema::create('loyalty_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 10); // earn | reverse | redeem | expire
            $table->integer('points'); // signed: earn +, the others −
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
            $table->unique(['type', 'order_id', 'reference_type', 'reference_id'], 'loyalty_ledger_unique_entry');
        });

        $this->backfillLedger();
    }

    /** One entry per historical event, so the ledger starts complete. */
    private function backfillLedger(): void
    {
        $now = now();

        DB::table('orders')->where('points_earned', '>', 0)->orderBy('id')->chunkById(500, function ($orders) use ($now) {
            $rows = [];
            foreach ($orders as $order) {
                $rows[] = ['user_id' => $order->user_id, 'order_id' => $order->id, 'type' => 'earn', 'points' => (int) $order->points_earned,
                    'reference_type' => 'order', 'reference_id' => $order->id, 'occurred_at' => $order->created_at ?? $now, 'created_at' => $now];
                if ($order->points_status === 'redeemed') {
                    $rows[] = ['user_id' => $order->user_id, 'order_id' => $order->id, 'type' => 'redeem', 'points' => -(int) $order->points_earned,
                        'reference_type' => 'order', 'reference_id' => $order->id, 'occurred_at' => $order->updated_at ?? $now, 'created_at' => $now];
                }
            }
            DB::table('loyalty_ledger')->insert($rows);
        });

        foreach (DB::table('loyalty_point_reversals')->where('points', '>', 0)->get() as $reversal) {
            DB::table('loyalty_ledger')->insert([
                'user_id' => $reversal->user_id, 'order_id' => $reversal->order_id, 'type' => 'reverse', 'points' => -(int) $reversal->points,
                'reference_type' => 'loyalty_point_reversal', 'reference_id' => $reversal->id, 'admin_id' => $reversal->admin_id,
                'occurred_at' => $reversal->created_at ?? $now, 'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_ledger');
        Schema::dropIfExists('admin_audit_logs');
        Schema::table('admins', fn (Blueprint $table) => $table->dropColumn(['role', 'is_active']));
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn([
            'low_stock_threshold', 'return_window_days', 'customer_can_cancel_pending', 'coupon_applies_to_delivery',
            'vaccine_reminders_enabled', 'medication_reminders_enabled',
        ]));
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_rule_id');
            $table->dropColumn('is_gift');
        });
        Schema::table('flash_sales', fn (Blueprint $table) => $table->dropColumn('ends_at'));
        Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn(['type', 'min_order_amount', 'max_discount_amount', 'per_user_limit', 'applies_to_delivery']));
    }
};
