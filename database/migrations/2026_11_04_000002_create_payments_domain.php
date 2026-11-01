<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments as auditable records.
 *
 * - payments: one row per payment attempt (COD, Paymob, or a legacy manual transfer). The source
 *   of truth for money received; orders.payment_status is a projection written by PaymentService.
 * - payment_events: every provider callback / internal payment event, with an idempotency key.
 * - payment_status_histories: every status change of a payment, with the actor.
 * - refunds: money given back, linked to the payment and (optionally) the return request.
 * - cod_settlements: cash handed over by a courier (or the counter), separate from collection.
 *
 * Payment methods: only cash on delivery and Paymob remain usable. ZainCash and Qi Card become
 * legacy (inactive, flagged is_legacy): their rows, account numbers and every historical order
 * stay readable; checkout no longer accepts them. Paymob is created inactive; it can only be
 * activated once its credentials are configured.
 *
 * Backfill: one payment per existing order, mapped from orders.payment_status; accepted returns
 * become succeeded refunds (marked backfilled) because the previous code treated an accepted
 * return as refunded. No order amount is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('provider', 30)->default('offline')->after('code'); // cod | paymob | manual_transfer
            $table->boolean('is_legacy')->default(false)->after('is_active');
        });

        DB::table('payment_methods')->where('code', 'cash')->update(['provider' => 'cod']);
        DB::table('payment_methods')->whereIn('code', ['zaincash', 'qicard'])->update(['provider' => 'manual_transfer', 'is_active' => false, 'is_legacy' => true]);

        $countryId = DB::table('countries')->where('is_default', true)->value('id');
        if (!DB::table('payment_methods')->where('code', 'paymob')->exists()) {
            DB::table('payment_methods')->insert([
                'country_id' => $countryId, 'code' => 'paymob', 'provider' => 'paymob',
                'name_ar' => 'الدفع الإلكتروني (بطاقة / محفظة)', 'name_en' => 'Online payment (card / wallet)',
                'requires_receipt' => false, 'is_active' => false, 'is_legacy' => false, 'sort_order' => 2,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('cod_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('delivery_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('expected_amount', 12, 2);
            $table->decimal('received_amount', 12, 2);
            $table->decimal('difference', 12, 2)->default(0);
            $table->string('received_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('settled_at');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30);
            $table->string('internal_reference', 40)->unique();
            $table->string('provider_order_id', 100)->nullable();
            $table->string('provider_transaction_id', 100)->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->nullable();
            $table->string('status', 30);
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('failure_reason')->nullable();
            // Cash on delivery: collection (customer paid the courier) is not settlement (we got the cash).
            $table->string('collection_status', 30)->nullable(); // pending | collected | partially_collected | failed
            $table->decimal('collected_amount', 12, 2)->default(0);
            $table->string('collected_via', 30)->nullable(); // courier | counter
            $table->foreignId('collected_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('collected_at')->nullable();
            $table->foreignId('cod_settlement_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->foreignId('verified_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('receipt_path')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_transaction_id']);
            $table->index(['provider', 'provider_order_id']);
            $table->index(['order_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index(['provider', 'collection_status', 'settled_at']);
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('event_type', 50);
            $table->string('provider_event_id', 100)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->boolean('signature_valid')->nullable();
            $table->string('result', 30)->default('received'); // received | processed | duplicate | rejected | failed | ignored
            $table->text('error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['payment_id', 'created_at']);
            $table->index(['provider', 'result', 'created_at']);
        });

        Schema::create('payment_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('actor_type', 20); // admin | customer | provider | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['payment_id', 'created_at']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('return_request_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->nullable();
            $table->string('method', 20); // gateway | cash | manual
            $table->string('status', 20)->default('pending'); // pending | processing | succeeded | failed | canceled
            $table->string('provider_refund_id', 100)->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->string('reason')->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('refund_status', 20)->default('none')->after('payment_verified_at'); // none | partial | full
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('refund_status');
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        $now = now();
        $methods = DB::table('payment_methods')->get()->keyBy('code');
        $settledShipments = DB::table('shipments')->whereNotNull('cod_settled_at')->pluck('cod_settled_at', 'order_id');

        DB::table('orders')->orderBy('id')->chunkById(500, function ($orders) use ($methods, $now, $settledShipments) {
            $acceptedReturns = DB::table('return_requests')->whereIn('order_id', $orders->pluck('id'))->where('status', 'accepted')->get()->groupBy('order_id');

            foreach ($orders as $order) {
                $method = $methods[$order->payment_method] ?? null;
                $provider = $method->provider ?? ($order->payment_method === 'cash' ? 'cod' : 'manual_transfer');
                $isCod = $provider === 'cod';

                $status = match (true) {
                    $order->payment_status === 'paid' => 'paid',
                    $order->payment_status === 'refunded' => 'refunded',
                    $order->payment_status === 'awaiting_verification' => 'awaiting_verification',
                    $order->status === 'canceled' => 'canceled',
                    default => 'pending',
                };
                $collected = $isCod && in_array($status, ['paid', 'refunded'], true);
                $paidAt = in_array($status, ['paid', 'refunded'], true) ? ($order->payment_verified_at ?? $order->completed_at ?? $order->updated_at) : null;

                $refunds = $acceptedReturns[$order->id] ?? collect();
                $refunded = round($refunds->sum(fn ($r) => (float) ($r->approved_amount ?? $r->refund_amount ?? 0)), 2);

                $paymentId = DB::table('payments')->insertGetId([
                    'order_id' => $order->id, 'payment_method_id' => $order->payment_method_id ?? $method->id ?? null,
                    'provider' => $provider, 'internal_reference' => sprintf('PAY-L%08d', $order->id),
                    'amount' => $order->total_price, 'currency' => $order->currency_code, 'status' => $status,
                    'collection_status' => $isCod ? ($collected ? 'collected' : ($status === 'canceled' ? null : 'pending')) : null,
                    'collected_amount' => $collected ? $order->total_price : 0,
                    'collected_via' => $collected ? ($order->address_id ? 'courier' : 'counter') : null,
                    'collected_at' => $collected ? $paidAt : null,
                    'settled_at' => $settledShipments[$order->id] ?? null,
                    'refunded_amount' => $refunded,
                    'verified_by' => $order->payment_verified_by, 'verified_at' => $order->payment_verified_at,
                    'receipt_path' => $order->payment_image, 'paid_at' => $paidAt,
                    'refunded_at' => $status === 'refunded' ? $order->updated_at : null,
                    'metadata' => json_encode(['backfilled' => true]),
                    'created_at' => $order->created_at ?? $now, 'updated_at' => $now,
                ]);

                DB::table('payment_status_histories')->insert([
                    'payment_id' => $paymentId, 'from_status' => null, 'to_status' => $status,
                    'actor_type' => 'system', 'note' => 'Status at migration time', 'created_at' => $now,
                ]);

                foreach ($refunds as $return) {
                    $amount = (float) ($return->approved_amount ?? $return->refund_amount ?? 0);
                    if ($amount <= 0) {
                        continue;
                    }
                    $refundId = DB::table('refunds')->insertGetId([
                        'reference' => 'TMP-'.$return->id, 'order_id' => $order->id, 'payment_id' => $paymentId,
                        'return_request_id' => $return->id, 'amount' => $amount, 'currency' => $order->currency_code,
                        'method' => 'manual', 'status' => 'succeeded', 'reason' => 'Accepted return #'.$return->id,
                        'processed_by' => $return->decided_by, 'processed_at' => $return->decided_at ?? $return->updated_at,
                        'metadata' => json_encode(['backfilled' => true]), 'created_at' => $return->decided_at ?? $now, 'updated_at' => $now,
                    ]);
                    DB::table('refunds')->where('id', $refundId)->update(['reference' => sprintf('RF-%06d', $refundId)]);
                }

                if ($refunded > 0) {
                    DB::table('orders')->where('id', $order->id)->update([
                        'refunded_amount' => $refunded,
                        'refund_status' => $refunded >= (float) $order->total_price ? 'full' : 'partial',
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['refund_status', 'refunded_amount']));
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_status_histories');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('cod_settlements');

        // Restore the methods as they were; the Paymob row is removed only if no order used it.
        if (!DB::table('orders')->where('payment_method', 'paymob')->exists()) {
            DB::table('payment_methods')->where('code', 'paymob')->delete();
        }
        DB::table('payment_methods')->whereIn('code', ['zaincash', 'qicard'])->update(['is_active' => true]);
        Schema::table('payment_methods', fn (Blueprint $table) => $table->dropColumn(['provider', 'is_legacy']));
    }
};
