<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ejaz 17-Jul: SmartEPT becomes an integration HUB.
 * - api_keys: key-authenticated access for external devices/apps to PUSH punches
 *   in and PULL attendance out (documented public API under /api/v1).
 * - integration_targets: outbound webhooks — SmartEPT pushes attendance to
 *   SmartPRS or any other app, HMAC-signed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');                         // "SmartPRS", "Gate device", ...
            $t->string('prefix', 12)->index();          // shown in UI (sk_live_ab12…)
            $t->string('key_hash');                     // sha256 of the full secret; secret shown once
            $t->json('scopes')->nullable();             // ['ingest','read'] — what this key may do
            $t->timestamp('last_used_at')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('integration_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');                         // "SmartPRS Production"
            $t->string('url');                          // https://smartprs.com/api/ingest/attendance
            $t->string('secret')->nullable();           // HMAC signing secret
            $t->json('events')->nullable();             // ['attendance.daily']
            $t->boolean('active')->default(true);
            $t->timestamp('last_pushed_at')->nullable();
            $t->string('last_status')->nullable();      // "200 OK" / error text
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_targets');
        Schema::dropIfExists('api_keys');
    }
};
