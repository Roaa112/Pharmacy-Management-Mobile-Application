<?php

// use Illuminate\Database\Migrations\Migration;
// use Illuminate\Database\Schema\Blueprint;
// use Illuminate\Support\Facades\Schema;

// return new class extends Migration
// {
//     /**
//      * Run the migrations.
//      */
//     public function up(): void
//     {
//         Schema::create('discounts', function (Blueprint $table) {
//             $table->id();
//             $table->string('title');
//             $table->decimal('precentage', 5, 2);
//             $table->date('start_date');
//             $table->date('end_date')->nullable();
//             $table->date('expire_date')->nullable();
//             $table->boolean('is_active')->default(true);
//             $table->timestamps();
//         });
//     }

//     /**
//      * Reverse the migrations.
//      */
//     public function down(): void
//     {
//         Schema::dropIfExists('discounts');
//     }
// };
