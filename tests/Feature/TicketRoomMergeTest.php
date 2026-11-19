<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\TicketRoomMerger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The unique ticket room key must merge pre-existing duplicates instead of
 * dropping messages, reads or files.
 */
class TicketRoomMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_ticket_rooms_are_merged_into_the_oldest_room(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $unit = Unit::create(['nama_unit' => 'Umum', 'is_aktif' => true]);
        $subUnit = SubUnit::create(['unit_id' => $unit->id, 'nama_layanan' => 'Perbaikan', 'is_aktif' => true]);
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $subUnit->id,
            'form_data' => [],
            'status' => 'open',
        ]);

        // Legacy schema: recreate duplicates by dropping the unique key first.
        Schema::table('conversations', function ($table) {
            $table->dropUnique(['ticket_id', 'type']);
        });

        $keep = Conversation::create([
            'type' => 'ticket',
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'last_message_at' => now()->subHour(),
        ]);
        $dupe = Conversation::create([
            'type' => 'ticket',
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'last_message_at' => now(),
        ]);

        $first = Message::create([
            'conversation_id' => $keep->id,
            'sender_type' => User::class,
            'sender_id' => $user->id,
            'body' => 'pesan lama',
        ]);
        $second = Message::create([
            'conversation_id' => $dupe->id,
            'sender_type' => Admin::class,
            'sender_id' => $admin->id,
            'body' => 'pesan baru',
        ]);

        $removed = TicketRoomMerger::mergeDuplicates();

        $this->assertSame(1, $removed);
        $this->assertSame(1, Conversation::where('ticket_id', $ticket->id)->count());
        $this->assertSame($keep->id, Message::whereKey($first->id)->value('conversation_id'));
        $this->assertSame($keep->id, Message::whereKey($second->id)->value('conversation_id'));
        $this->assertNotNull($keep->fresh()->last_message_at);

        Schema::table('conversations', function ($table) {
            $table->unique(['ticket_id', 'type']);
        });

        $this->expectException(QueryException::class);

        Conversation::create([
            'type' => 'ticket',
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
        ]);
    }
}
