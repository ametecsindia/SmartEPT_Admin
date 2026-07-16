<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The specialized, per-concern policy tables. Grouped in one migration because
 * they share the same shape (company-scoped, named, versioned) and are always
 * composed together by the Policy Engine into the agent policy bundle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screenshot_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('interval_seconds')->default(600);
            $t->boolean('random_enabled')->default(false);
            $t->boolean('on_violation')->default(true);
            $t->boolean('on_blocked_website')->default(true);
            $t->boolean('on_blocked_app')->default(true);
            $t->boolean('during_idle')->default(false);
            $t->boolean('active_work_only')->default(true);
            $t->json('excluded_apps')->nullable();
            $t->json('excluded_websites')->nullable();
            $t->boolean('blur_sensitive')->default(false);
            $t->unsignedInteger('retention_days')->default(30);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('webcam_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->boolean('presence_enabled')->default(false);
            $t->boolean('photo_enabled')->default(false);
            $t->unsignedInteger('photo_interval_seconds')->default(1800);
            $t->boolean('photo_on_violation')->default(false);
            $t->boolean('photo_on_attendance')->default(false);
            $t->decimal('face_confidence_threshold', 4, 2)->default(0.70);
            $t->unsignedInteger('away_threshold_seconds')->default(60);
            $t->unsignedInteger('camera_blocked_threshold_seconds')->default(30);
            $t->unsignedInteger('multiple_face_threshold_seconds')->default(15);
            $t->unsignedInteger('photo_retention_days')->default(30);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('application_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->json('allowed_apps')->nullable();
            $t->json('blocked_apps')->nullable();
            $t->json('categories')->nullable();
            $t->enum('action_on_blocked', ['LOG', 'WARN', 'NOTIFY', 'SCREENSHOT', 'CLOSE'])->default('WARN');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('website_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->json('allowed_sites')->nullable();
            $t->json('blocked_sites')->nullable();
            $t->json('categories')->nullable();
            $t->boolean('track_full_url')->default(false);
            $t->enum('action_on_blocked', ['LOG', 'WARN', 'NOTIFY', 'SCREENSHOT', 'BLOCK'])->default('WARN');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('network_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->json('allowed_public_ips')->nullable();
            $t->json('allowed_lan_ranges')->nullable();
            $t->json('allowed_ssids')->nullable();
            $t->json('allowed_vpn_networks')->nullable();
            $t->boolean('remote_work_allowed')->default(true);
            $t->boolean('block_unknown_network')->default(false);
            $t->enum('action_on_unauthorized', ['LOG', 'WARN', 'NOTIFY', 'BLOCK_LOGIN', 'REQUIRE_APPROVAL'])->default('WARN');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('device_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->boolean('require_antivirus')->default(false);
            $t->boolean('require_firewall')->default(false);
            $t->boolean('require_disk_encryption')->default(false);
            $t->string('min_os_version')->nullable();
            $t->json('blocked_software')->nullable();
            $t->json('settings')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('usb_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->enum('action', ['LOG', 'ALERT', 'VIOLATION', 'SCREENSHOT', 'REQUIRE_APPROVAL', 'BLOCK_STORAGE'])->default('LOG');
            $t->json('allowed_device_classes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('vpn_proxy_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->json('approved_tools')->nullable();
            $t->json('blocked_tools')->nullable();
            $t->enum('action_on_unauthorized', ['ALERT', 'VIOLATION', 'SCREENSHOT'])->default('ALERT');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('break_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->json('break_types')->nullable();
            $t->boolean('auto_detect_from_idle')->default(true);
            $t->boolean('requires_approval')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('attendance_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->unsignedInteger('late_grace_minutes')->default(10);
            $t->unsignedInteger('early_logout_grace_minutes')->default(10);
            $t->json('attendance_sources')->nullable();
            $t->unsignedInteger('min_working_hours')->default(8);
            $t->json('settings')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('compliance_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('description')->nullable();
            $t->json('settings')->nullable(); // per-violation severity + action + score penalty
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'compliance_policies', 'attendance_policies', 'break_policies', 'vpn_proxy_policies',
            'usb_policies', 'device_policies', 'network_policies', 'website_policies',
            'application_policies', 'webcam_policies', 'screenshot_policies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
