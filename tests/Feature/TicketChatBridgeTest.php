<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketLog;
use App\Models\Unit;
use App\Models\SubUnit;
use App\Models\User;
use App\Services\TicketChatBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketChatBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_ticket_reply_to_chat_creates_conversation_and_message(): void
    {
        $user = User::factory()->create();
        $admin = Admin::create([
            'name' => 'Admin Sarpras',
            'username' => 'adminsarpras',
            'email' => 'sarpras@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $unit = Unit::create(['nama_unit' => 'Sarpras', 'is_aktif' => true]);
        $subUnit = SubUnit::create([
            'unit_id' => $unit->id,
            'nama_layanan' => 'Perbaikan AC',
            'is_aktif' => true,
        ]);
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $subUnit->id,
            'assigned_admin_id' => $admin->id,
            'form_data' => [],
            'status' => 'on_proses',
            'priority' => 'Sedang',
        ]);

        $log = TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => null,
            'aksi' => 'balasan',
            'catatan' => 'Halo pak, airnya makin deras nih.',
            'timestamp' => now(),
        ]);

        $message = TicketChatBridgeService::syncTicketReplyToChat($ticket, $log);

        $this->assertNotNull($message);
        $this->assertEquals('Halo pak, airnya makin deras nih.', $message->body);
        $this->assertEquals($ticket->id, $message->ticket_id);
        $this->assertEquals(User::class, $message->sender_type);
        $this->assertEquals($user->id, $message->sender_id);

        $this->assertDatabaseHas('conversations', [
            'type' => 'user_admin_direct',
            'user_id' => $user->id,
            'admin_one_id' => $admin->id,
        ]);
    }

    public function test_sync_chat_message_to_ticket_log(): void
    {
        $user = User::factory()->create();
        $admin = Admin::create([
            'name' => 'Operator IT',
            'username' => 'operatorit',
            'email' => 'operator@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $unit = Unit::create(['nama_unit' => 'IT', 'is_aktif' => true]);
        $subUnit = SubUnit::create([
            'unit_id' => $unit->id,
            'nama_layanan' => 'Jaringan',
            'is_aktif' => true,
        ]);

        $ticket = Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $subUnit->id,
            'assigned_admin_id' => $admin->id,
            'form_data' => [],
            'status' => 'on_proses',
            'priority' => 'Sedang',
        ]);

        $conversation = Conversation::create([
            'type' => 'user_admin_direct',
            'user_id' => $user->id,
            'admin_one_id' => $admin->id,
            'last_message_at' => now(),
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => Admin::class,
            'sender_id' => $admin->id,
            'ticket_id' => $ticket->id,
            'body' => 'Siap, teknisi sedang meluncur membawa kabel baru.',
        ]);

        $log = TicketChatBridgeService::syncChatMessageToTicket($message);

        $this->assertNotNull($log);
        $this->assertEquals('balasan', $log->aksi);
        $this->assertEquals('Siap, teknisi sedang meluncur membawa kabel baru.', $log->catatan);
        $this->assertEquals($admin->id, $log->admin_id);
        $this->assertEquals($ticket->id, $log->ticket_id);

        $this->assertDatabaseHas('ticket_logs', [
            'ticket_id' => $ticket->id,
            'admin_id' => $admin->id,
            'aksi' => 'balasan',
            'catatan' => 'Siap, teknisi sedang meluncur membawa kabel baru.',
        ]);
    }
}
