<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field-force location trail pushed by the Caller mobile app server (6-Oct-2026).
 * One row per GPS fix; (company_id, external_id) makes a re-delivery a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_force_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->string('employee_code', 64);
            $t->string('external_id', 96);
            $t->timestamp('captured_at');
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->decimal('accuracy_m', 8, 1)->nullable();
            $t->unsignedTinyInteger('battery_pct')->nullable();
            $t->string('geo_status', 16)->nullable();      // within | outside | no-rule
            $t->decimal('distance_km', 8, 3)->nullable();
            $t->string('source', 32)->default('CALLER');
            $t->timestamps();

            $t->unique(['company_id', 'external_id'], 'ffl_company_external_unique');
            $t->index(['company_id', 'employee_id', 'captured_at'], 'ffl_co_emp_captured_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_force_locations');
    }
};
