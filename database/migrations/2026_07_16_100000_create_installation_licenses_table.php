<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2-1 Licence wiring: the single-row local licence record for THIS installation.
 * Stores the key + the signed entitlement bundle cached from SmartEPT Central.
 * HARD WALL: only licence metadata is ever exchanged with Central.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_licenses', function (Blueprint $table) {
            $table->id();
            $table->string('license_key', 64)->nullable();
            // unconfigured | active | expired | suspended | revoked | unknown_key | server_mismatch
            $table->string('status', 32)->default('unconfigured');
            $table->json('bundle')->nullable();          // cached entitlement bundle from Central
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('unreachable_since')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_licenses');
    }
};
