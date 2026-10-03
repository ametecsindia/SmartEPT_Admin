<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 30-Sep-2026 (Ejaz): WhatsApp-style ticks on the chat.
 *   ✓        sent      — stored on the server
 *   ✓✓ grey  delivered — the employee's PC picked it up on its fast poll
 *   ✓✓ blue  read      — the chat window was on the employee's screen (read_at)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employee_chat_messages', 'delivered_at')) return;
        Schema::table('employee_chat_messages', function (Blueprint $t) {
            $t->timestamp('delivered_at')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employee_chat_messages', 'delivered_at')) return;
        Schema::table('employee_chat_messages', function (Blueprint $t) {
            $t->dropColumn('delivered_at');
        });
    }
};
