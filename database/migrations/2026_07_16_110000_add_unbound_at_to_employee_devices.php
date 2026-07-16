<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2-3 device management: an admin can UNBIND a device (kills its token,
 * frees the licence seat, blocks silent re-registration) and later approve
 * a re-bind. unbound_at doubles as the "blocked" flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_devices', function (Blueprint $table) {
            $table->timestamp('unbound_at')->nullable()->after('last_heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::table('employee_devices', function (Blueprint $table) {
            $table->dropColumn('unbound_at');
        });
    }
};
