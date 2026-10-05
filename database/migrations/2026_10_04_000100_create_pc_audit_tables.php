<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 04-Oct-2026 — PC Audit Log (Enforcer + Commander).
 *
 * Additive only: two NEW tables, nothing existing is touched.
 *   pc_audit_events  — what the SmartEPT Agent Service (software, USB/devices, downloads,
 *                      files to USB, network shares) and the agent (clicks) report. Everything
 *                      else in the audit log is read from the tables that already hold it.
 *   pc_audit_reports — the "Run audit for all PCs" background jobs and their CSV files.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pc_audit_events')) {
            Schema::create('pc_audit_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->string('device_uuid', 64);
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('kind', 40);                 // click | software_installed | usb_storage | download | ...
                $t->timestamp('occurred_at')->nullable();
                $t->string('title', 255)->nullable();
                $t->json('detail')->nullable();
                $t->string('outcome', 120)->nullable();  // e.g. "Blocked by SmartEPT"
                $t->char('fingerprint', 40);             // sha1 — a resent batch is ignored, never doubled
                $t->timestamp('created_at')->nullable();
            });
        }
        // Short explicit names (MySQL 1059, see the endpoint-security migration). Guarded so a
        // half-run migrate is completed by running it again.
        foreach ([
            ['pc_audit_events', 'pcaudit_dev_fp_uq', ['device_uuid', 'fingerprint'], 'unique'],
            ['pc_audit_events', 'pcaudit_co_dev_time_idx', ['company_id', 'device_uuid', 'occurred_at'], 'index'],
        ] as [$table, $name, $cols, $kind]) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->{$kind}($cols, $name));
            }
        }

        if (! Schema::hasTable('pc_audit_reports')) {
            Schema::create('pc_audit_reports', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->date('date_from');
                $t->date('date_to');
                $t->string('status', 20)->default('queued'); // queued | running | done | failed
                $t->unsignedInteger('devices')->default(0);
                $t->unsignedInteger('rows')->default(0);
                $t->string('file', 255)->nullable();
                $t->string('error', 255)->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pc_audit_reports');
        Schema::dropIfExists('pc_audit_events');
    }
};
