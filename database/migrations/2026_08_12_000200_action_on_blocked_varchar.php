<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ENUM strike FOUR (11-Aug-2026, live SmartEPT_Admin): the shared Rules screen
 * sends ONE action_on_blocked value to BOTH policy tables, but the two ENUMs
 * disagree — application_policies allows CLOSE (not BLOCK), website_policies
 * allows BLOCK (not CLOSE). Saving CLOSE on a website policy died with
 * strict-MySQL 1265 "Data truncated" (live, PolicyController::update).
 *
 * Standing Ametecs lesson: prefer VARCHAR over ENUM — the application layer
 * owns the value set. Convert both columns once and for all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // sqlite (tests) stores enums as TEXT already
        }

        DB::statement("ALTER TABLE `application_policies` MODIFY `action_on_blocked` VARCHAR(20) NOT NULL DEFAULT 'WARN'");
        DB::statement("ALTER TABLE `website_policies` MODIFY `action_on_blocked` VARCHAR(20) NOT NULL DEFAULT 'WARN'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Union of both original sets so no existing row is truncated on rollback.
        DB::statement("ALTER TABLE `application_policies` MODIFY `action_on_blocked` "
            . "ENUM('LOG','WARN','NOTIFY','SCREENSHOT','CLOSE','BLOCK') NOT NULL DEFAULT 'WARN'");
        DB::statement("ALTER TABLE `website_policies` MODIFY `action_on_blocked` "
            . "ENUM('LOG','WARN','NOTIFY','SCREENSHOT','CLOSE','BLOCK') NOT NULL DEFAULT 'WARN'");
    }
};
