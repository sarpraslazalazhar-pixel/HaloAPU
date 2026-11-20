<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_workload_assessments', function (Blueprint $table) {
            $table->renameColumn('request_count', 'legacy_request_count');
        });

        Schema::table('ticket_workload_assessments', function (Blueprint $table) {
            $table->unsignedSmallInteger('legacy_request_count')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('ticket_workload_assessments')->whereNull('legacy_request_count')->exists()) {
            throw new RuntimeException('Cannot restore request counts after assessments without a count have been saved.');
        }

        Schema::table('ticket_workload_assessments', function (Blueprint $table) {
            $table->renameColumn('legacy_request_count', 'request_count');
        });

        Schema::table('ticket_workload_assessments', function (Blueprint $table) {
            $table->unsignedSmallInteger('request_count')->nullable(false)->change();
        });
    }
};
