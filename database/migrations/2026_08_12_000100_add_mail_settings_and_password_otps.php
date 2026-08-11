<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client-wise SMTP + console forgot-password (Ejaz, 11-Aug-2026):
 *  - companies.mail_settings: the company's OWN SMTP relay (host/port/username/
 *    encrypted password/encryption/from) used for its alerts and password
 *    resets; NULL = fall back to the global SMTP in Settings, then .env.
 *  - password_otps: 6-digit email OTPs for the console forgot-password flow
 *    (hashed, 10-minute expiry, 5 attempts max).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'mail_settings')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->json('mail_settings')->nullable()->after('storage_settings')
                    ->comment('Company-own SMTP relay; null = global Settings SMTP, then .env');
            });
        }

        if (! Schema::hasTable('password_otps')) {
            Schema::create('password_otps', function (Blueprint $t) {
                $t->id();
                $t->string('email', 190)->index();
                $t->string('otp_hash');
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->timestamp('expires_at');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('password_otps');
        if (Schema::hasColumn('companies', 'mail_settings')) {
            Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('mail_settings'));
        }
    }
};
