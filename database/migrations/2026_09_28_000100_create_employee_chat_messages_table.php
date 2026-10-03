<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 28-Sep-2026: admin ↔ employee chat (employee drawer on the admin console ↔ a chat
 * popup on the agent). One thread per employee; sender says which side wrote it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_chat_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('employee_id')->index();
            $t->string('sender', 10);                        // ADMIN | EMPLOYEE
            $t->foreignId('sender_user_id')->nullable();     // the admin who wrote it
            $t->text('body');
            $t->timestamp('read_at')->nullable();            // when the OTHER side saw it
            $t->timestamps();
            $t->index(['employee_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_chat_messages');
    }
};
