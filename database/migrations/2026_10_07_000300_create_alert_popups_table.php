<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 07-Oct-2026 (Ejaz): Audit & Ops → Notifications — every alert can be sent as Email and/or a
 * Popup Alert. A popup is one row per console user; the console polls and shows it once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alert_popups')) {
            return;
        }
        Schema::create('alert_popups', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('kind', 40);
            $t->string('title', 250);
            $t->text('body');
            $t->timestamp('seen_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_popups');
    }
};
