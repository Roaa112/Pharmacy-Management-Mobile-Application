<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The store's market. The store currently operates in Egypt only and uses EGP: only Egypt is
 * created, and existing cities (governorates) are attached to it. Nothing existing is renamed or
 * deleted. (Databases migrated with an earlier version of this file, which also created Iraq, are
 * corrected by 2026_11_06_000001_make_store_egypt_only.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('iso2', 2)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->char('currency_code', 3);
            $table->string('timezone', 64);
            $table->string('phone_code', 8);
            $table->string('week_start', 10)->default('saturday');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        $now = now();
        $defaultId = DB::table('countries')->insertGetId([
            'iso2' => 'EG', 'name_ar' => 'مصر', 'name_en' => 'Egypt', 'currency_code' => 'EGP', 'timezone' => 'Africa/Cairo',
            'phone_code' => '+20', 'week_start' => 'saturday', 'is_active' => true, 'is_default' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        Schema::table('cities', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('name_ar')->nullable()->after('name');
            $table->string('name_en')->nullable()->after('name_ar');
            $table->boolean('is_active')->default(true)->after('name_en');
        });
        DB::table('cities')->whereNull('country_id')->update(['country_id' => $defaultId]);

        Schema::table('areas', function (Blueprint $table) {
            $table->string('name_ar')->nullable()->after('name');
            $table->string('name_en')->nullable()->after('name_ar');
            $table->boolean('is_active')->default(true)->after('name_en');
        });

        Schema::table('addresses', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('city')->constrained('cities')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->after('city_id')->constrained('areas')->nullOnDelete();
        });

        // Link existing addresses to a city by exact name (governorate first, then city).
        foreach (DB::table('cities')->get(['id', 'name', 'name_ar', 'name_en']) as $city) {
            $names = array_values(array_filter([$city->name, $city->name_ar, $city->name_en]));
            DB::table('addresses')->whereNull('city_id')
                ->where(fn ($q) => $q->whereIn('governorate', $names)->orWhereIn('city', $names))
                ->update(['city_id' => $city->id]);
        }
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
            $table->dropConstrainedForeignId('city_id');
        });
        Schema::table('areas', fn (Blueprint $table) => $table->dropColumn(['name_ar', 'name_en', 'is_active']));
        Schema::table('cities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('country_id');
            $table->dropColumn(['name_ar', 'name_en', 'is_active']);
        });
        Schema::dropIfExists('countries');
    }
};
