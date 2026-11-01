<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ZainCash has been removed as an active payment provider. Historical ZainCash records are preserved.
 *
 * Removes the only ZainCash *setting*: settings.zaincash (the wallet number customers transferred to).
 * It is not needed for history: orders reference the payment method (orders.payment_method = 'zaincash',
 * orders.payment_method_id, payments.provider = 'manual_transfer'), never this column, and the number
 * is kept on the legacy payment_methods row, where it was copied in 2026_11_03_000003. Before
 * dropping, the number is copied again if that row has none, so nothing is lost.
 *
 * Not touched (history): orders, payments, payment events, receipts, the legacy 'zaincash'
 * payment_methods row (re-asserted inactive and legacy), and the Qi Card column settings.keycard
 * (out of scope of this change).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('settings', 'zaincash')) {
            return;
        }

        $number = DB::table('settings')->orderBy('id')->whereNotNull('zaincash')->where('zaincash', '!=', '')->value('zaincash');

        DB::table('payment_methods')->where('code', 'zaincash')
            ->update(array_filter([
                'is_active' => false,
                'is_legacy' => Schema::hasColumn('payment_methods', 'is_legacy') ? true : null,
                'updated_at' => now(),
            ], fn ($value) => $value !== null));

        if ($number !== null) {
            DB::table('payment_methods')->where('code', 'zaincash')
                ->where(fn ($q) => $q->whereNull('account_number')->orWhere('account_number', ''))
                ->update(['account_number' => $number]);
        }

        Schema::table('settings', fn (Blueprint $table) => $table->dropColumn('zaincash'));
    }

    /** Restores the column with the number kept on the legacy payment method. */
    public function down(): void
    {
        if (Schema::hasColumn('settings', 'zaincash')) {
            return;
        }

        Schema::table('settings', fn (Blueprint $table) => $table->string('zaincash', 30)->nullable());

        $number = DB::table('payment_methods')->where('code', 'zaincash')->value('account_number');
        DB::table('settings')->update(['zaincash' => $number]);
    }
};
