<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The loyalty programme becomes ledger based (see LoyaltyLedger):
     *  - loyalty_ledger keeps every movement and its credit entries become "lots" with the points
     *    still left in them (remaining_points), a status and an expiry; redemptions consume lots
     *    oldest-expiry first and record which lots they used (loyalty_ledger_allocations);
     *  - the rules are settings (settings.loyalty_*), defaulting to the behaviour so far;
     *  - rewards and their redemptions.
     * The existing history is converted, not discarded: each order's earn becomes a lot holding
     * what its own reversals, redemption and expiry left, exactly the per-order numbers so far.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('loyalty_enabled')->default(true);
            $table->boolean('points_never_expire')->default(false);
            $table->decimal('loyalty_min_order_amount', 10, 2)->default(0);
            $table->unsignedInteger('loyalty_max_points_per_order')->nullable();
            $table->string('loyalty_earn_basis', 10)->default('subtotal');      // subtotal | paid
            $table->boolean('loyalty_earn_on_shipping')->default(false);
            $table->boolean('loyalty_discounts_reduce_points')->default(false);
            $table->boolean('loyalty_earn_on_cod')->default(true);
            // When earned points can be used: immediate | paid | completed.
            $table->string('loyalty_activation', 12)->default(config('pharmacy.points_redeem_requires_completion', true) ? 'completed' : 'immediate');
            $table->decimal('loyalty_point_value', 10, 4)->default(0);          // currency per point at checkout; 0 = off
            $table->unsignedInteger('loyalty_min_redeem_points')->default(0);
            $table->unsignedInteger('loyalty_max_redeem_points_per_order')->nullable();
            $table->boolean('loyalty_combine_with_discounts')->default(true);
            $table->boolean('loyalty_allow_partial_redemption')->default(true);
            $table->boolean('loyalty_allow_multiple_redemptions')->default(true);
            $table->unsignedSmallInteger('loyalty_expiry_warning_days')->default(7);
            $table->unsignedSmallInteger('loyalty_redemption_validity_days')->nullable()->default(30);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('points_redeemed')->default(0);
            $table->decimal('points_discount_amount', 10, 2)->default(0);
        });

        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->string('type', 20)->change();
            $table->integer('remaining_points')->nullable();       // lots only: points still usable
            $table->string('status', 12)->default('posted');       // lots: pending|active|depleted|expired|void; others: posted
            $table->string('description')->nullable();
            $table->timestamp('earned_at')->nullable();             // when the lot became usable
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamp('updated_at')->nullable();

            $table->index(['user_id', 'status', 'expires_at'], 'loyalty_ledger_user_lots');
            $table->index(['status', 'expires_at'], 'loyalty_ledger_expiry');
            $table->index(['user_id', 'type', 'occurred_at'], 'loyalty_ledger_history');
            $table->index(['reference_type', 'reference_id'], 'loyalty_ledger_reference');
        });

        Schema::create('loyalty_ledger_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('loyalty_ledger')->restrictOnDelete();
            $table->foreignId('lot_id')->constrained('loyalty_ledger')->restrictOnDelete();
            $table->integer('points'); // + taken from the lot, − given back to it
            $table->timestamp('created_at')->nullable();
            $table->index(['lot_id', 'entry_id']);
        });

        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();                   // stable identifier (import/export)
            $table->string('type', 20)->default('product');          // product | free_delivery | voucher | custom
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_size_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->unsignedInteger('points_required');
            $table->unsignedInteger('stock')->nullable();            // null = unlimited
            $table->unsignedInteger('redeemed_count')->default(0);   // live (not cancelled) redemptions, units
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->unsignedInteger('total_limit')->nullable();
            $table->text('terms')->nullable();
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'starts_at', 'ends_at']);
            $table->index('sort_order');
        });

        Schema::create('loyalty_redemptions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('reward_id')->nullable()->constrained('loyalty_rewards')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 12);                             // reward | checkout
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('points');
            $table->decimal('value', 10, 2)->nullable();              // checkout: the discount the points paid
            $table->string('status', 12)->index();                     // pending | approved | fulfilled | cancelled | expired
            $table->json('reward_snapshot')->nullable();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('loyalty_ledger')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('fulfilled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'reward_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        $this->convertHistory();
    }

    private function convertHistory(): void
    {
        DB::table('loyalty_ledger')->where('type', 'earn')->update(['type' => 'earned']);
        DB::table('loyalty_ledger')->where('type', 'redeem')->update(['type' => 'redeemed']);
        DB::table('loyalty_ledger')->where('type', 'expire')->update(['type' => 'expired']);
        DB::table('loyalty_ledger')->where('type', 'reverse')->where('reference_type', 'order_cancellation')->update(['type' => 'reversed']);
        DB::table('loyalty_ledger')->where('type', 'reverse')->update(['type' => 'refunded']);

        $settings = DB::table('settings')->orderBy('id')->first();
        $validityDays = max(0, (int) ($settings->points_validity_days ?? 0));
        $activation = $settings->loyalty_activation ?? 'completed';
        $now = now();

        DB::table('loyalty_ledger')->where('type', 'earned')->whereNull('remaining_points')->orderBy('id')->chunkById(500, function ($lots) use ($validityDays, $activation, $now) {
            foreach ($lots as $lot) {
                $order = DB::table('orders')->where('id', $lot->order_id)->first();
                $debits = DB::table('loyalty_ledger')->where('order_id', $lot->order_id)->where('id', '!=', $lot->id)->where('points', '<', 0)->orderBy('id')->get();
                $remaining = (int) $lot->points;

                foreach ($debits as $debit) {
                    $take = min($remaining, -(int) $debit->points);
                    if ($take > 0) {
                        DB::table('loyalty_ledger_allocations')->insert(['entry_id' => $debit->id, 'lot_id' => $lot->id, 'points' => $take, 'created_at' => $now]);
                        $remaining -= $take;
                    }
                    $key = $debit->type === 'expired' ? "expired:lot:{$lot->id}" : "{$debit->type}:{$debit->reference_type}:{$debit->reference_id}";
                    DB::table('loyalty_ledger')->where('id', $debit->id)->update(['idempotency_key' => $key]);
                }

                $earnedAt = $order?->created_at ? Carbon::parse($order->created_at) : Carbon::parse($lot->occurred_at);
                $types = $debits->pluck('type');
                $activated = $order && ($activation === 'immediate' || $order->status === 'completed'
                    || ($activation === 'paid' && in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)));

                $status = match (true) {
                    $order?->status === 'canceled' => 'void',
                    $types->contains('expired') => 'expired',
                    $remaining === 0 => 'depleted',
                    $activated => 'active',
                    default => 'pending',
                };

                DB::table('loyalty_ledger')->where('id', $lot->id)->update([
                    'remaining_points' => $remaining,
                    'status' => $status,
                    'idempotency_key' => "earned:order:{$lot->order_id}",
                    // Points so far were valid from the order date: existing lots keep that expiry.
                    'earned_at' => $status === 'pending' ? null : $earnedAt,
                    'expires_at' => $status === 'pending' ? null : $earnedAt->copy()->addDays($validityDays),
                    'redeemed_at' => $types->contains('redeemed') ? $debits->firstWhere('type', 'redeemed')->occurred_at : null,
                    'reversed_at' => $types->intersect(['reversed', 'refunded'])->isNotEmpty() ? $debits->whereIn('type', ['reversed', 'refunded'])->last()->occurred_at : null,
                    'description' => "Order #{$lot->order_id}",
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_redemptions');
        Schema::dropIfExists('loyalty_rewards');
        Schema::dropIfExists('loyalty_ledger_allocations');

        // MySQL dropped the implicit user_id foreign-key index once the composite indexes covered it:
        // give the foreign key a plain index again before removing them.
        Schema::table('loyalty_ledger', fn (Blueprint $table) => $table->index('user_id', 'loyalty_ledger_user_id_index'));

        Schema::table('loyalty_ledger', function (Blueprint $table) {
            $table->dropIndex('loyalty_ledger_user_lots');
            $table->dropIndex('loyalty_ledger_expiry');
            $table->dropIndex('loyalty_ledger_history');
            $table->dropIndex('loyalty_ledger_reference');
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['remaining_points', 'status', 'description', 'earned_at', 'expires_at', 'redeemed_at', 'reversed_at', 'metadata', 'idempotency_key', 'updated_at']);
        });
        // Entries created by the new programme (adjustments, bonuses, rewards) have no old type.
        DB::table('loyalty_ledger')->whereNull('order_id')->delete();
        DB::table('loyalty_ledger')->whereIn('type', ['cancelled', 'adjusted', 'bonus'])->delete();
        DB::table('loyalty_ledger')->where('type', 'earned')->update(['type' => 'earn']);
        DB::table('loyalty_ledger')->where('type', 'redeemed')->update(['type' => 'redeem']);
        DB::table('loyalty_ledger')->where('type', 'expired')->update(['type' => 'expire']);
        DB::table('loyalty_ledger')->whereIn('type', ['reversed', 'refunded'])->update(['type' => 'reverse']);

        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['points_redeemed', 'points_discount_amount']));
        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn([
            'loyalty_enabled', 'points_never_expire', 'loyalty_min_order_amount', 'loyalty_max_points_per_order', 'loyalty_earn_basis',
            'loyalty_earn_on_shipping', 'loyalty_discounts_reduce_points', 'loyalty_earn_on_cod', 'loyalty_activation', 'loyalty_point_value',
            'loyalty_min_redeem_points', 'loyalty_max_redeem_points_per_order', 'loyalty_combine_with_discounts', 'loyalty_allow_partial_redemption',
            'loyalty_allow_multiple_redemptions', 'loyalty_expiry_warning_days', 'loyalty_redemption_validity_days',
        ]));
    }
};
