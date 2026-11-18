<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-introduces ticket_id on conversations, this time as the room key for
 * per-ticket chat: one conversation per ticket, shared by the user, the
 * assigned admin, and any admin watching that ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('user_id')
                ->constrained('tickets')->nullOnDelete();
            $table->index(['ticket_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['ticket_id']);
            $table->dropIndex(['ticket_id', 'type']);
            $table->dropColumn('ticket_id');
        });
    }
};
