<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin #9 (24-Jul): let the organiser set a "remind X minutes before" lead time.
 * When set, participants + organiser get a reminder popup with a Join button as the
 * meeting approaches. Null = no reminder (existing behaviour).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            if (! Schema::hasColumn('meetings', 'reminder_minutes')) {
                $table->unsignedSmallInteger('reminder_minutes')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            if (Schema::hasColumn('meetings', 'reminder_minutes')) {
                $table->dropColumn('reminder_minutes');
            }
        });
    }
};
