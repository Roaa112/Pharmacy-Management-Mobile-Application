<?php

use App\Modules\Admin\Authorization\RbacSynchronizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Contact replies reach the customer only as an app notification (push + history), not by email. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_message_replies', function (Blueprint $table) {
            $table->dropColumn('emailed_to');
        });

        app(RbacSynchronizer::class)->sync(); // refresh the reply permission's description
    }

    public function down(): void
    {
        Schema::table('contact_message_replies', function (Blueprint $table) {
            $table->string('emailed_to')->nullable()->after('push_devices');
        });
    }
};
