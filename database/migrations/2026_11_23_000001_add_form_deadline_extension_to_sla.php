<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            // true = field ini memperpanjang SLA penyelesaian; NULL untuk field lain (jangan simpan false)
            $table->boolean('extends_resolution_sla')->nullable()->after('wajib');
            $table->unique(['sub_unit_id', 'extends_resolution_sla'], 'form_fields_subunit_extends_sla_unique');
        });

        Schema::table('ticket_sla_tracking', function (Blueprint $table) {
            $table->dateTime('sla_resolution_form_deadline')->nullable()->after('sla_resolution_base_deadline');
            $table->dateTime('sla_resolution_policy_deadline')->nullable()->after('sla_resolution_form_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_sla_tracking', function (Blueprint $table) {
            $table->dropColumn(['sla_resolution_form_deadline', 'sla_resolution_policy_deadline']);
        });

        Schema::table('form_fields', function (Blueprint $table) {
            $table->dropUnique('form_fields_subunit_extends_sla_unique');
            $table->dropColumn('extends_resolution_sla');
        });
    }
};
