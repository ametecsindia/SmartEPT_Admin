<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 30-Sep-2026 (Ejaz): Schedule Report → "Send the report to WhatsApp" — the full-report snapshot
 * image to the Company Admin(s) and/or the numbers typed on the schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_schedules', function (Blueprint $t) {
            if (! Schema::hasColumn('report_schedules', 'whatsapp_enabled')) {
                $t->boolean('whatsapp_enabled')->default(false);
                $t->boolean('whatsapp_admins')->default(false);  // the Company Admins' phones (Users screen)
                $t->json('whatsapp_numbers')->nullable();         // extra numbers, e.g. ["919876543210"]
            }
        });
    }

    public function down(): void
    {
        Schema::table('report_schedules', function (Blueprint $t) {
            if (Schema::hasColumn('report_schedules', 'whatsapp_enabled')) {
                $t->dropColumn(['whatsapp_enabled', 'whatsapp_admins', 'whatsapp_numbers']);
            }
        });
    }
};
