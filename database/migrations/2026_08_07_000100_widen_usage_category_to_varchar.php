<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Usage 'category' is written from POLICY-DEFINED labels: ComplianceEvaluator
 * returns strtoupper($cat) for whatever category an admin names a site/app
 * (e.g. "tracked" -> TRACKED). The column was an ENUM with a fixed list, so any
 * custom category outside that list failed under MySQL strict mode
 * (SQLSTATE 01000 / 1265 "Data truncated") and the whole usage batch was dropped.
 *
 * Fix at the source: widen both usage 'category' columns to VARCHAR so any
 * policy category label is stored. (Ejaz, 7-Aug-2026)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ponytail: raw ALTER (no doctrine/dbal needed); MySQL/MariaDB only, which is what SmartEPT runs.
        DB::statement("ALTER TABLE `employee_website_usage_logs` MODIFY `category` VARCHAR(32) NOT NULL DEFAULT 'NEUTRAL'");
        DB::statement("ALTER TABLE `employee_app_usage_logs` MODIFY `category` VARCHAR(32) NOT NULL DEFAULT 'NEUTRAL'");
    }

    public function down(): void
    {
        // Best-effort revert to the original enums. Rows holding a custom
        // category (e.g. TRACKED) would be truncated by MySQL on the way back —
        // acceptable for a rollback of a widening change.
        DB::statement("ALTER TABLE `employee_website_usage_logs` MODIFY `category` "
            . "ENUM('PRODUCTIVE','NON_PRODUCTIVE','NEUTRAL','RESTRICTED','BLOCKED','BANKING_CRM','COMMUNICATION','TRAINING','CLIENT_SPECIFIC') "
            . "NOT NULL DEFAULT 'NEUTRAL'");
        DB::statement("ALTER TABLE `employee_app_usage_logs` MODIFY `category` "
            . "ENUM('PRODUCTIVE','NON_PRODUCTIVE','NEUTRAL','RESTRICTED','BLOCKED','CLIENT_REQUIRED','SYSTEM','COMMUNICATION') "
            . "NOT NULL DEFAULT 'NEUTRAL'");
    }
};
