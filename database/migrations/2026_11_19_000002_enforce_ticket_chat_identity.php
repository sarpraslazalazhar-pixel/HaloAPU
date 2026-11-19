<?php

use App\Services\TicketRoomMerger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One room per ticket, and one chat message per ticket log.
 *
 * Duplicate ticket rooms (created by a race before the unique key) are merged
 * into the oldest room first: messages, reads and attachments keep their ids.
 * `messages.ticket_log_id` replaces body/time dedupe heuristics; existing rows
 * stay unlinked rather than guessing pairs.
 */
return new class extends Migration
{
    public function up(): void
    {
        TicketRoomMerger::mergeDuplicates();

        Schema::table('conversations', function (Blueprint $table) {
            // The unique key is added first: its leftmost column keeps the
            // ticket_id foreign key indexed while the old index is dropped.
            $table->unique(['ticket_id', 'type']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['ticket_id', 'type']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('ticket_log_id')->nullable()->after('ticket_id')
                ->constrained('ticket_logs')->nullOnDelete();
            $table->unique('ticket_log_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['ticket_log_id']);
            $table->dropConstrainedForeignId('ticket_log_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['ticket_id', 'type']);
            $table->index(['ticket_id', 'type']);
        });
    }
};
