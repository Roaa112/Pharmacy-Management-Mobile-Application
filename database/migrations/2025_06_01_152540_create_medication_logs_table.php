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
     Schema::create('medication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('medication_id')->constrained()->onDelete('cascade');
            $table->date('shown_date');
            $table->time('time');       
            $table->enum('status', ['pending', 'taken', 'missed'])->default('pending'); 
            $table->timestamps();
            $table->unique(['user_id', 'medication_id', 'shown_date', 'time'], 'unique_medication_log');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medication_logs');
    }
};
