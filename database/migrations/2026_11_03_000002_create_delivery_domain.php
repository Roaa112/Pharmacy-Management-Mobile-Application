<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery zones, rates, providers and shipments.
 *
 * Every existing delivery_prices row becomes one zone (same name) covering the city with
 * that exact name, with one rate at the same price. delivery_prices is kept, untouched, for
 * rollback and audit; the application no longer reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('legacy_delivery_price_id')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('delivery_zone_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            // One zone per city (area_id null) or per area.
            $table->unique(['city_id', 'area_id']);
        });

        Schema::create('delivery_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->string('type', 30); // internal | external_manual | external_api
            $table->string('adapter', 50)->default('manual');
            $table->text('credentials')->nullable(); // encrypted json
            $table->text('webhook_secret')->nullable(); // encrypted
            $table->string('tracking_url_template')->nullable();
            $table->boolean('supports_cod')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('delivery_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('fee', 10, 2);
            $table->decimal('free_over_amount', 10, 2)->nullable();
            $table->decimal('min_order_amount', 10, 2)->nullable();
            $table->unsignedSmallInteger('eta_min_days')->nullable();
            $table->unsignedSmallInteger('eta_max_days')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_status_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_provider_id')->constrained()->cascadeOnDelete();
            $table->string('external_status', 100);
            $table->string('internal_status', 30);
            $table->timestamps();
            $table->unique(['delivery_provider_id', 'external_status']);
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('pending');
            $table->string('tracking_number')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('tracking_url')->nullable();
            $table->decimal('fee_charged', 10, 2)->default(0);
            $table->decimal('provider_cost', 10, 2)->nullable();
            $table->decimal('cod_amount', 10, 2)->default(0);
            $table->timestamp('cod_collected_at')->nullable();
            $table->timestamp('cod_settled_at')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->json('address_snapshot')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->boolean('needs_attention')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['delivery_provider_id', 'external_reference']);
            $table->index('tracking_number');
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->nullable(); // null = unmapped provider status
            $table->string('raw_status', 100)->nullable();
            $table->string('source', 20); // admin | webhook | poll | system
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->json('payload')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->boolean('applied')->default(false);
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->migrateDeliveryPrices();
    }

    private function migrateDeliveryPrices(): void
    {
        $now = now();
        $countryId = DB::table('countries')->where('is_default', true)->value('id')
            ?? DB::table('countries')->orderBy('id')->value('id');

        DB::table('delivery_providers')->insert([
            'country_id' => $countryId, 'name' => 'Pharmacy delivery', 'code' => 'internal', 'type' => 'internal',
            'adapter' => 'manual', 'supports_cod' => true, 'is_active' => true, 'is_default' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (DB::table('delivery_prices')->orderBy('id')->get() as $price) {
            $name = trim($price->governorate);

            $cityId = DB::table('cities')
                ->where(fn ($q) => $q->where('name', $name)->orWhere('name_ar', $name)->orWhere('name_en', $name))
                ->value('id');

            if (!$cityId) {
                $cityId = DB::table('cities')->insertGetId([
                    'country_id' => $countryId, 'name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $zoneId = DB::table('delivery_zones')->insertGetId([
                'country_id' => DB::table('cities')->where('id', $cityId)->value('country_id') ?? $countryId,
                'name' => $name, 'is_active' => true, 'legacy_delivery_price_id' => $price->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            if (!DB::table('delivery_zone_locations')->where('city_id', $cityId)->whereNull('area_id')->exists()) {
                DB::table('delivery_zone_locations')->insert([
                    'delivery_zone_id' => $zoneId, 'city_id' => $cityId, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            DB::table('delivery_rates')->insert([
                'delivery_zone_id' => $zoneId, 'fee' => $price->price, 'priority' => 0, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('addresses')->whereNull('city_id')
                ->where(fn ($q) => $q->where('governorate', $name)->orWhere('city', $name))
                ->update(['city_id' => $cityId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('provider_status_maps');
        Schema::dropIfExists('delivery_rates');
        Schema::dropIfExists('delivery_providers');
        Schema::dropIfExists('delivery_zone_locations');
        Schema::dropIfExists('delivery_zones');
    }
};
