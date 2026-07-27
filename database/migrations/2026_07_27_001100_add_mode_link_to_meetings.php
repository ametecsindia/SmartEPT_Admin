<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** QA: Online vs Offline meetings — Online carries a Google Meet/Zoom link (Join button),
 *  Offline carries venue + host contact. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $t) {
            if (! Schema::hasColumn('meetings', 'meeting_mode')) {
                $t->string('meeting_mode', 10)->default('online')->after('status');
            }
            if (! Schema::hasColumn('meetings', 'meeting_link')) {
                $t->string('meeting_link', 1000)->nullable()->after('meeting_mode');
            }
            if (! Schema::hasColumn('meetings', 'venue')) {
                $t->string('venue', 500)->nullable()->after('meeting_link');
            }
            if (! Schema::hasColumn('meetings', 'host_contact')) {
                $t->string('host_contact', 255)->nullable()->after('venue');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $t) {
            $t->dropColumn(['meeting_mode', 'meeting_link', 'venue', 'host_contact']);
        });
    }
};
