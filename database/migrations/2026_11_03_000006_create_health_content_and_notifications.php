<?php

use App\Modules\HealthService\DefaultHealthContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Health content managed from the dashboard (vaccine schedule template, pregnancy week content),
 * device tokens and the reminder delivery log.
 *
 * Seeds reproduce exactly what the code generated before, so the API answers the same.
 * Existing children's vaccines are not touched: they stay as the snapshot they were created with.
 */
return new class extends Migration
{

    public function up(): void
    {
        // Production created children/vaccines outside migrations; their original migrations are
        // guarded with hasTable. Make sure every column the code relies on exists.
        Schema::table('vaccines', function (Blueprint $table) {
            if (!Schema::hasColumn('vaccines', 'is_completed')) {
                $table->boolean('is_completed')->default(false);
            }
            if (!Schema::hasColumn('vaccines', 'description')) {
                $table->text('description')->nullable();
            }
        });

        Schema::create('vaccine_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 50);
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->unsignedSmallInteger('offset_value');
            $table->string('offset_unit', 10); // day | week | month | year
            $table->unsignedSmallInteger('reminder_days_before')->default(3);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
            $table->index(['country_id', 'code']);
        });

        Schema::table('vaccines', function (Blueprint $table) {
            $table->foreignId('vaccine_schedule_item_id')->nullable()->after('child_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('schedule_version')->nullable()->after('vaccine_schedule_item_id');
            $table->string('vaccine_name_en')->nullable()->after('vaccine_name');
            $table->text('description_en')->nullable()->after('description');
            $table->unsignedSmallInteger('reminder_days_before')->nullable();
            $table->timestamp('completed_at')->nullable();
        });

        Schema::create('pregnancy_week_contents', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('week_from');
            $table->unsignedTinyInteger('week_to');
            $table->string('size_ar');
            $table->string('size_en');
            $table->string('weight_ar', 50);
            $table->string('weight_en', 50);
            $table->string('length_ar', 50);
            $table->string('length_en', 50);
            $table->string('image')->nullable();
            $table->text('tip_ar')->nullable();
            $table->text('tip_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512);
            $table->string('token_hash', 64)->unique();
            $table->string('platform', 10); // android | ios | web
            $table->string('locale', 5)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50); // vaccine_reminder | medication_reminder | ...
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id');
            $table->string('occurrence', 32); // date or datetime of the event reminded about
            $table->string('status', 20); // sent | failed | skipped
            $table->unsignedSmallInteger('devices')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            // The guard against duplicate reminders.
            $table->unique(['type', 'reference_type', 'reference_id', 'occurrence'], 'notification_deliveries_unique');
        });

        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        $this->seed();
    }

    private function seed(): void
    {
        $now = now();
        $countryId = DB::table('countries')->where('is_default', true)->value('id');

        foreach (DefaultHealthContent::DEFAULT_VACCINES as $i => [$code, $value, $unit, $nameAr, $nameEn, $descAr, $descEn]) {
            DB::table('vaccine_schedule_items')->insert([
                'country_id' => $countryId, 'code' => $code, 'name_ar' => $nameAr, 'name_en' => $nameEn,
                'description_ar' => $descAr, 'description_en' => $descEn, 'offset_value' => $value, 'offset_unit' => $unit,
                'reminder_days_before' => 3, 'sort_order' => $i + 1, 'is_active' => true, 'version' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (DefaultHealthContent::DEFAULT_PREGNANCY_WEEKS as [$from, $to, $sizeAr, $sizeEn, $weightAr, $weightEn, $lengthAr, $lengthEn]) {
            DB::table('pregnancy_week_contents')->insert([
                'week_from' => $from, 'week_to' => $to, 'size_ar' => $sizeAr, 'size_en' => $sizeEn,
                'weight_ar' => $weightAr, 'weight_en' => $weightEn, 'length_ar' => $lengthAr, 'length_en' => $lengthEn,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('pregnancy_week_contents');
        Schema::table('vaccines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vaccine_schedule_item_id');
            $table->dropColumn(['schedule_version', 'vaccine_name_en', 'description_en', 'reminder_days_before', 'completed_at']);
        });
        Schema::dropIfExists('vaccine_schedule_items');
    }
};
