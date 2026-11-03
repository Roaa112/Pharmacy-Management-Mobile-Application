<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Import & export centre: one row per import and per export (history, files, counts).
     *
     * Products get a stable business identifier, products.code (PRD-000123 for existing ones),
     * so an exported file can update the same products when imported again: product names are
     * never used to match. Sizes are matched by their SKU / barcode (already unique).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->unique()->after('id');
        });
        DB::table('products')->whereNull('code')->orderBy('id')->chunkById(500, function ($products) {
            foreach ($products as $product) {
                DB::table('products')->where('id', $product->id)->update(['code' => sprintf('PRD-%06d', $product->id)]);
            }
        });

        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 40);
            $table->string('mode', 10);                     // create | update | upsert
            $table->string('format', 5);                    // csv | xlsx
            $table->string('original_name');
            $table->string('file_path');                    // private (local disk)
            $table->string('status', 24)->default('pending'); // pending | processing | completed | completed_with_errors | failed | cancelled
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('to_create')->default(0);
            $table->unsignedInteger('to_update')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->string('errors_path')->nullable();
            $table->string('results_path')->nullable();
            $table->json('summary')->nullable();
            $table->string('failure_message', 1000)->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['entity', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('export_runs', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 40);
            $table->string('format', 5);
            $table->json('filters')->nullable();
            $table->json('columns')->nullable();
            $table->string('status', 12)->default('pending'); // pending | processing | completed | failed
            $table->unsignedInteger('row_count')->default(0);
            $table->string('file_path')->nullable();
            $table->string('failure_message', 1000)->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['entity', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_runs');
        Schema::dropIfExists('import_runs');
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
