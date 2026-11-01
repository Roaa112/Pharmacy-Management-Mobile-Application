<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The store currently operates in Egypt only and uses EGP.
 *
 * For databases migrated with the earlier multi-country setup (Iraq/IQD as default market, Egypt
 * inactive). A fresh install already has Egypt only and this migration changes nothing there.
 *
 * - Egypt becomes the only active market (EGP, Africa/Cairo, +20).
 * - Store configuration that was attached to another country is moved to Egypt: cities, delivery
 *   zones, delivery providers, payment methods, vaccine schedule items and warehouses. These rows
 *   are setup, not history.
 * - History is not touched: orders keep the country_id and currency_code they were recorded with;
 *   payments, refunds, shipments and addresses are unchanged.
 * - Another country row is deleted only when nothing references it any more (no order); otherwise
 *   it stays, inactive, so those historical orders still resolve their country.
 */
return new class extends Migration
{
    private const CONFIG_TABLES = ['cities', 'delivery_zones', 'delivery_providers', 'payment_methods', 'vaccine_schedule_items', 'warehouses'];

    public function up(): void
    {
        $now = now();
        $egypt = DB::table('countries')->where('iso2', 'EG')->value('id');

        if (!$egypt) {
            $egypt = DB::table('countries')->insertGetId([
                'iso2' => 'EG', 'name_ar' => 'مصر', 'name_en' => 'Egypt', 'currency_code' => 'EGP', 'timezone' => 'Africa/Cairo',
                'phone_code' => '+20', 'week_start' => 'saturday', 'is_active' => true, 'is_default' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        DB::table('countries')->where('id', $egypt)->update([
            'currency_code' => 'EGP', 'timezone' => 'Africa/Cairo', 'phone_code' => '+20',
            'is_active' => true, 'is_default' => true, 'updated_at' => $now,
        ]);

        $others = DB::table('countries')->where('id', '!=', $egypt)->pluck('id');
        if ($others->isEmpty()) {
            return;
        }

        DB::table('countries')->whereIn('id', $others)->update(['is_active' => false, 'is_default' => false, 'updated_at' => $now]);

        foreach (self::CONFIG_TABLES as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'country_id')) {
                continue;
            }

            if ($table === 'payment_methods') {
                $this->movePaymentMethods($others->all(), $egypt);
                continue;
            }

            DB::table($table)->whereIn('country_id', $others)->update(['country_id' => $egypt]);
        }

        // A country row nothing points at any more is removed; one used by historical orders stays.
        foreach ($others as $id) {
            if (!DB::table('orders')->where('country_id', $id)->exists()) {
                DB::table('countries')->where('id', $id)->delete();
            }
        }
    }

    /**
     * payment_methods is unique on (code, country_id). A method whose code Egypt already has keeps
     * its row (orders may reference it) but is retired instead of moved.
     */
    private function movePaymentMethods(array $others, int $egypt): void
    {
        foreach (DB::table('payment_methods')->whereIn('country_id', $others)->get() as $method) {
            $duplicate = DB::table('payment_methods')->where('country_id', $egypt)->where('code', $method->code)->exists();

            if ($duplicate) {
                DB::table('payment_methods')->where('id', $method->id)->update(array_filter([
                    'is_active' => false,
                    'is_legacy' => Schema::hasColumn('payment_methods', 'is_legacy') ? true : null,
                ], fn ($v) => $v !== null));
            } else {
                DB::table('payment_methods')->where('id', $method->id)->update(['country_id' => $egypt]);
            }
        }
    }

    /** One-way: the Iraqi market is not recreated (no data was deleted that it would need). */
    public function down(): void
    {
    }
};
