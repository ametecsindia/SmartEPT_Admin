<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Release-1 · Outbound-mail audit trail (SmartPRS pattern, simplified).
 * Every MailService::send() writes a row regardless of transport outcome, so
 * admins can verify "was the credentials mail attempted?" even on LAN installs
 * where MAIL_MAILER=log and nothing actually leaves the box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to');
            $table->string('subject');
            $table->string('kind')->nullable();   // e.g. USER_CREDENTIALS
            $table->enum('status', ['sent', 'failed', 'skipped'])->default('sent');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_logs');
    }
};
