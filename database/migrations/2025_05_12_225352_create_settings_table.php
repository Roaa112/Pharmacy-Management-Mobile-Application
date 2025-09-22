<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->text('privacy_policy_ar')->nullable();
            $table->text('privacy_policy_en')->nullable();
            $table->text('terms_conditions_ar')->nullable();
            $table->text('terms_conditions_en')->nullable();
            $table->string('facebook')->nullable();
            $table->string('instagram')->nullable();
            $table->string('tiktok')->nullable();
            $table->string('youtube')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('spend_x')->nullable();
            $table->string('get_y')->nullable();
            $table->string('map_location_url')->nullable();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
