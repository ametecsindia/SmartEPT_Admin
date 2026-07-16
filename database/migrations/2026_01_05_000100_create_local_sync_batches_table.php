<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger for offline sync. A resent batch (same batch_uuid) is recognised and
 * not double-counted, so the client can retry safely after a lost response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_sync_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->nullable();
            $table->string('batch_uuid')->unique();
            $table->unsignedInteger('event_count')->default(0);
            $table->timestamp('received_at')->nullable();
            $table->boolean('processed')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'device_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_sync_batches');
    }
};
