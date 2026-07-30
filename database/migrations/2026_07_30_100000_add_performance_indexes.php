<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'idx_tickets_user_created');
            $table->index(['unit_id', 'created_at'], 'idx_tickets_unit_created');
            $table->index(['status', 'created_at'], 'idx_tickets_status_created');
        });

        Schema::table('ticket_sla_tracking', function (Blueprint $table) {
            $table->index(['is_response_breached', 'is_resolution_breached'], 'idx_sla_breaches');
            $table->index(['ticket_id', 'resolved_at'], 'idx_sla_ticket_resolved');
        });

        Schema::table('csats', function (Blueprint $table) {
            $table->index(['created_at', 'rating'], 'idx_csats_created_rating');
        });

        if (Schema::hasTable('room_vehicle_bookings')) {
            Schema::table('room_vehicle_bookings', function (Blueprint $table) {
                $table->index(['nama_aset', 'status', 'tanggal_mulai', 'tanggal_selesai'], 'idx_rvb_aset_status_dates');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('idx_tickets_user_created');
            $table->dropIndex('idx_tickets_unit_created');
            $table->dropIndex('idx_tickets_status_created');
        });

        Schema::table('ticket_sla_tracking', function (Blueprint $table) {
            $table->dropIndex('idx_sla_breaches');
            $table->dropIndex('idx_sla_ticket_resolved');
        });

        Schema::table('csats', function (Blueprint $table) {
            $table->dropIndex('idx_csats_created_rating');
        });

        if (Schema::hasTable('room_vehicle_bookings')) {
            Schema::table('room_vehicle_bookings', function (Blueprint $table) {
                $table->dropIndex('idx_rvb_aset_status_dates');
            });
        }
    }
};
