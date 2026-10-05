<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 03-Oct-2026 — SmartEPT Endpoint Security (Enforcer + Commander).
 *
 * Additive only: five NEW tables and two NEW role-matrix cards. No existing table,
 * column or permission is touched. down() removes exactly what up() created.
 *
 * Status is ONE row per machine (upserted), never a row per heartbeat. Threats,
 * events and commands keep history. VARCHAR, not ENUM — the app owns the value sets.
 */
return new class extends Migration
{
    private const CARDS = [
        ['security_overview', 'Endpoint security — status, threats & scans'],
        ['security_policy', 'Endpoint security — compliance policy'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('endpoint_security_status')) {
            Schema::create('endpoint_security_status', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('enforcement_machine_id')->unique();
                $t->string('device_uuid', 64)->nullable()->index();
                $t->string('provider', 120)->nullable();
                $t->string('provider_type', 20)->default('unknown'); // defender | third_party | none | unknown
                $t->json('installed_providers')->nullable();
                $t->boolean('antivirus_installed')->nullable();
                $t->boolean('antivirus_enabled')->nullable();
                $t->boolean('realtime_enabled')->nullable();
                $t->boolean('behavior_enabled')->nullable();
                $t->boolean('ioav_enabled')->nullable();
                $t->string('running_mode', 40)->nullable();
                $t->string('signature_version', 40)->nullable();
                $t->timestamp('signature_updated_at')->nullable();
                $t->boolean('signature_outdated')->nullable();
                $t->timestamp('last_quick_scan_at')->nullable();
                $t->timestamp('last_full_scan_at')->nullable();
                $t->integer('threat_count')->nullable();
                $t->integer('active_threat_count')->nullable();
                $t->boolean('firewall_domain')->nullable();
                $t->boolean('firewall_private')->nullable();
                $t->boolean('firewall_public')->nullable();
                $t->json('errors')->nullable();
                $t->string('compliance', 20)->default('UNKNOWN'); // COMPLIANT | ACTION_REQUIRED | NON_COMPLIANT | UNKNOWN
                $t->json('compliance_issues')->nullable();
                $t->unsignedTinyInteger('score')->nullable();
                $t->json('open_alerts')->nullable(); // alert state, so an unchanged problem never re-alerts
                $t->string('capability', 40)->nullable();
                $t->string('agent_version', 32)->nullable();
                $t->timestamp('checked_at')->nullable();
                $t->timestamp('received_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('endpoint_security_threats')) {
            Schema::create('endpoint_security_threats', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('enforcement_machine_id');
                $t->string('threat_id', 40);
                $t->string('threat_name', 255)->nullable();
                $t->unsignedTinyInteger('severity')->nullable();
                $t->string('status', 30)->default('unknown');
                $t->boolean('active')->default(false);
                $t->boolean('action_success')->nullable();
                $t->timestamp('detected_at')->nullable();
                $t->timestamp('status_changed_at')->nullable();
                $t->json('resources')->nullable(); // already redacted on the endpoint
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('endpoint_security_events')) {
            Schema::create('endpoint_security_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('enforcement_machine_id');
                $t->string('source', 10)->default('defender'); // defender | server (alerts)
                $t->bigInteger('record_id')->nullable();          // Defender RecordId; null for server rows
                $t->integer('event_id')->nullable();
                $t->string('kind', 40);
                $t->string('detail', 120)->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('endpoint_security_commands')) {
            Schema::create('endpoint_security_commands', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('enforcement_machine_id');
                $t->string('uuid', 36)->unique();
                $t->string('type', 30);
                $t->json('parameters')->nullable();
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->string('requested_ip', 64)->nullable();
                $t->timestamp('requested_at')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('received_at')->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->string('status', 20)->default('queued'); // queued|received|running|completed|failed|expired|cancelled
                $t->string('error_code', 50)->nullable();
                $t->string('error_message', 255)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('endpoint_security_policies')) {
            Schema::create('endpoint_security_policies', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->unique();
                $t->json('settings')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }

        // Composite indexes get SHORT explicit names: Laravel's generated ones are 62–65
        // characters and MySQL's limit is 64 (error 1059, 03-Oct-2026). Added outside
        // Schema::create and guarded, so a database where an earlier run stopped half-way
        // (tables created, index missing) is completed by simply running migrate again.
        foreach ([
            ['endpoint_security_threats', 'endpoint_security_threats_company_id_index', ['company_id'], 'index'],
            ['endpoint_security_threats', 'es_threats_machine_threat_uq', ['enforcement_machine_id', 'threat_id'], 'unique'],
            ['endpoint_security_events', 'es_events_machine_record_uq', ['enforcement_machine_id', 'record_id'], 'unique'],
            ['endpoint_security_events', 'es_events_machine_time_idx', ['enforcement_machine_id', 'occurred_at'], 'index'],
            ['endpoint_security_commands', 'es_commands_machine_status_idx', ['enforcement_machine_id', 'status'], 'index'],
        ] as [$table, $name, $cols, $kind]) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->{$kind}($cols, $name));
            }
        }

        // Role matrix cards (Organisation → Roles). Super/Company Admin get both; every
        // other role starts with none — a new feature grants nobody anything silently.
        $ids = [];
        foreach (self::CARDS as [$key, $label]) {
            foreach (['view' => $label, 'edit' => $label . ' (edit)'] as $lv => $name) {
                $ids[] = Permission::updateOrCreate(['slug' => "card.endsec.$key.$lv"],
                    ['name' => $name, 'group' => 'Card access · Endpoint Security'])->id;
            }
        }
        foreach (Role::whereIn('slug', ['SUPER_ADMIN', 'COMPANY_ADMIN'])->get() as $role) {
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }

    public function down(): void
    {
        $slugs = [];
        foreach (self::CARDS as [$key]) {
            $slugs[] = "card.endsec.$key.view";
            $slugs[] = "card.endsec.$key.edit";
        }
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        foreach (Role::all() as $role) {
            $role->permissions()->detach($ids);
        }
        Permission::whereIn('id', $ids)->delete();

        foreach (['endpoint_security_policies', 'endpoint_security_commands', 'endpoint_security_events',
            'endpoint_security_threats', 'endpoint_security_status'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
