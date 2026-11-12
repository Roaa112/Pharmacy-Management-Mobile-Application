<?php

use App\Modules\Admin\Authorization\RbacSynchronizer;
use App\Modules\HealthService\Services\HealthRuleDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The health tools become dashboard-controlled:
 *  - health_result_rules: thresholds and messages of BMI, blood sugar and blood pressure results,
 *    seeded with the guideline categories the app used until now (same codes, same texts);
 *  - settings.health_settings: tools on/off, disclaimer, guidelines, ovulation and reminder settings.
 * Nothing changes for customers until an admin edits something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_result_rules', function (Blueprint $table) {
            $table->id();
            $table->string('metric', 30);                 // bmi | blood_sugar | blood_pressure
            $table->string('condition', 30)->nullable();  // blood sugar: fasting | after_meal | random; null = any
            $table->string('code', 50);                   // evaluation_code / status_code the app receives
            $table->integer('priority')->default(0);      // highest matching rule wins
            $table->unsignedSmallInteger('sort_order')->default(0);
            // from ≤ value < to; null = open. Secondary = diastolic (blood pressure).
            $table->decimal('primary_from', 8, 2)->nullable();
            $table->decimal('primary_to', 8, 2)->nullable();
            $table->decimal('secondary_from', 8, 2)->nullable();
            $table->decimal('secondary_to', 8, 2)->nullable();
            $table->string('label_ar');
            $table->string('label_en');
            $table->string('legacy_ar')->nullable();      // Arabic text older apps compare against (default: label_ar)
            $table->text('message_ar')->nullable();
            $table->text('message_en')->nullable();
            $table->boolean('requires_urgent_care')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['metric', 'is_active']);
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->json('health_settings')->nullable();
        });

        $now = now();
        DB::table('health_result_rules')->insert(array_map(
            fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now],
            HealthRuleDefaults::rows(),
        ));

        // New permission health_rules.manage (medical thresholds).
        app(RbacSynchronizer::class)->sync();
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('health_settings');
        });
        Schema::dropIfExists('health_result_rules');
    }
};
