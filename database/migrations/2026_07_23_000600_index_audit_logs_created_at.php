<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA Phase 6 (B13) — index audit_logs on (company_id, created_at) so an explicit
 * date/time range scans an index instead of the whole table. Best-effort create
 * (DBAL-free) so a re-run can't die on a duplicate; additive & reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('audit_logs', fn (Blueprint $t) => $t->index(['company_id', 'created_at'], 'ept_audit_co_created_idx'));
        } catch (\Throwable $e) {
            // index already present — fine
        }
    }

    public function down(): void
    {
        try {
            Schema::table('audit_logs', fn (Blueprint $t) => $t->dropIndex('ept_audit_co_created_idx'));
        } catch (\Throwable $e) {
            // already gone — fine
        }
    }
};
