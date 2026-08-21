<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_sla_tracking', function (Blueprint $table) {
            if (!Schema::hasColumn('ticket_sla_tracking', 'is_response_half_warned')) {
                $table->boolean('is_response_half_warned')->default(false)->after('is_response_breached');
            }
            if (!Schema::hasColumn('ticket_sla_tracking', 'is_resolution_half_warned')) {
                $table->boolean('is_resolution_half_warned')->default(false)->after('is_resolution_breached');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_sla_tracking', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_sla_tracking', 'is_response_half_warned')) {
                $table->dropColumn('is_response_half_warned');
            }
            if (Schema::hasColumn('ticket_sla_tracking', 'is_resolution_half_warned')) {
                $table->dropColumn('is_resolution_half_warned');
            }
        });
    }
};
