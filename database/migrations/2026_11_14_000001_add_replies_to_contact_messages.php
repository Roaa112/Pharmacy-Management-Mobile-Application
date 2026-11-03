<?php

use App\Modules\Admin\Authorization\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contact messages become conversations: the pharmacy replies from the dashboard (sent to the
 * customer by push, the in-app notification history and email) and the customer can answer back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_messages', function (Blueprint $table) {
            // open = waiting for the pharmacy, replied = the pharmacy answered last
            $table->string('status', 20)->default('open')->after('message')->index();
            $table->timestamp('last_activity_at')->nullable()->after('status')->index();
        });

        DB::table('contact_messages')->update(['last_activity_at' => DB::raw('created_at')]);

        Schema::create('contact_message_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->boolean('from_customer')->default(false);
            $table->text('body');
            // Delivery of a pharmacy reply (null on customer replies)
            $table->timestamp('sent_at')->nullable();
            $table->unsignedSmallInteger('push_devices')->nullable();
            $table->string('emailed_to')->nullable();
            $table->string('delivery_error')->nullable();
            $table->timestamps();
        });

        app(RbacSynchronizer::class)->sync();
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_message_replies');

        Schema::table('contact_messages', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['last_activity_at']);
            $table->dropColumn(['status', 'last_activity_at']);
        });
    }
};
