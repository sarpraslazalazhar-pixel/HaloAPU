<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The web chat and the mobile API must read and write the same conversation
 * rows, otherwise a "direct chat" started in the browser never appears on the
 * phone. These tests pin that contract.
 */
class ApiChatSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function directConversation(User $user, Admin $admin): Conversation
    {
        return Conversation::create([
            'type' => 'user_admin_direct',
            'user_id' => $user->id,
            'admin_one_id' => $admin->id,
            'last_message_at' => now(),
        ]);
    }

    public function test_mobile_inbox_lists_a_conversation_started_from_web(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $conversation = $this->directConversation($user, $admin);

        // Simulate the web chat: message written directly to the shared table.
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => User::class,
            'sender_id' => $user->id,
            'body' => 'halo dari web',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/conversations');

        $response->assertOk();
        $response->assertJsonPath('conversations.0.id', $conversation->id);
        $response->assertJsonPath('conversations.0.last_message', 'halo dari web');
    }

    public function test_mobile_message_is_visible_in_web_history(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $conversation = $this->directConversation($user, $admin);

        Sanctum::actingAs($user);

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'halo dari mobile',
        ])->assertCreated();

        // The web controller reads the same rows.
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'body' => 'halo dari mobile',
        ]);

        $this->assertNotNull($conversation->fresh()->last_message_at);
    }

    public function test_admin_inbox_includes_direct_chat_with_user(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $conversation = $this->directConversation($user, $admin);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => User::class,
            'sender_id' => $user->id,
            'body' => 'permisi admin',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/conversations');

        $response->assertOk();

        $ids = collect($response->json('conversations'))->pluck('id');

        $this->assertTrue($ids->contains($conversation->id), 'Admin must see the direct chat started by the user.');
    }

    public function test_message_can_carry_a_ticket_reference(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $conversation = $this->directConversation($user, $admin);

        $unit = Unit::create(['nama_unit' => 'Sarpras']);
        $subUnit = SubUnit::create(['unit_id' => $unit->id, 'nama_layanan' => 'Perbaikan']);

        $ticket = Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $subUnit->id,
            'status' => 'open',
            'form_data' => ['judul' => 'AC rusak'],
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'soal AC',
            'ticket_id' => $ticket->id,
        ])->assertCreated();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'ticket_id' => $ticket->id,
        ]);
    }

    public function test_user_cannot_read_someone_elses_conversation(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $admin = Admin::factory()->create();
        $conversation = $this->directConversation($owner, $admin);

        Sanctum::actingAs($intruder);

        $this->getJson("/api/conversations/{$conversation->id}")->assertForbidden();
    }

    public function test_opening_a_conversation_marks_it_read(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $conversation = $this->directConversation($user, $admin);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => Admin::class,
            'sender_id' => $admin->id,
            'body' => 'balasan admin',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/conversations/{$conversation->id}")->assertOk();

        $this->assertDatabaseHas('message_reads', [
            'message_id' => $message->id,
            'user_type' => User::class,
            'user_id' => $user->id,
        ]);
    }

    private function ticketFor(User $user): Ticket
    {
        $unit = Unit::create(['nama_unit' => 'Sarpras']);
        $subUnit = SubUnit::create(['unit_id' => $unit->id, 'nama_layanan' => 'Perbaikan']);

        return Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $subUnit->id,
            'status' => 'on_proses',
            'form_data' => ['judul' => 'AC rusak'],
        ]);
    }

    public function test_mobile_ticket_room_endpoint_creates_the_same_room_the_web_uses(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticketFor($user);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/tickets/{$ticket->id}/chat");

        $response->assertOk();
        $response->assertJsonPath('conversation.ticket_id', $ticket->id);

        $this->assertDatabaseHas('conversations', [
            'type' => 'ticket',
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_ticket_reply_from_mobile_lands_in_the_ticket_room_and_timeline(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticketFor($user);

        Sanctum::actingAs($user);

        $conversationId = $this->getJson("/api/tickets/{$ticket->id}/chat")
            ->assertOk()
            ->json('conversation.id');

        $this->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => 'halo dari mobile',
        ])->assertCreated();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'ticket_id' => $ticket->id,
            'body' => 'halo dari mobile',
        ]);
        $this->assertDatabaseHas('ticket_logs', [
            'ticket_id' => $ticket->id,
            'catatan' => 'halo dari mobile',
        ]);
    }

    public function test_mobile_inbox_lists_ticket_rooms(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticketFor($user);

        $conversation = Conversation::create([
            'type' => 'ticket',
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => User::class,
            'sender_id' => $user->id,
            'ticket_id' => $ticket->id,
            'body' => 'pesan tiket',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/conversations');

        $response->assertOk();
        $response->assertJsonPath('conversations.0.id', $conversation->id);
        $response->assertJsonPath('conversations.0.ticket.id', $ticket->id);
        $response->assertJsonPath('conversations.0.peer_name', 'Tiket #' . $ticket->formatted_id);
    }

    public function test_another_user_cannot_open_someone_elses_ticket_room(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $ticket = $this->ticketFor($owner);

        Sanctum::actingAs($intruder);

        $this->getJson("/api/tickets/{$ticket->id}/chat")->assertForbidden();
        $this->assertDatabaseMissing('conversations', ['ticket_id' => $ticket->id]);
    }

    public function test_assigned_admin_opens_the_ticket_room_from_mobile(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $ticket = $this->ticketFor($user);
        $ticket->update(['assigned_admin_id' => $admin->id]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/tickets/{$ticket->id}/chat")
            ->assertOk()
            ->assertJsonPath('conversation.ticket_id', $ticket->id);

        // The same room the web user chat uses.
        $this->assertSame(1, Conversation::where('type', 'ticket')->where('ticket_id', $ticket->id)->count());
    }

    public function test_unrelated_admin_cannot_open_the_ticket_room_from_mobile(): void
    {
        $user = User::factory()->create();
        $outsider = Admin::factory()->create();
        $ticket = $this->ticketFor($user);

        Sanctum::actingAs($outsider);

        $this->getJson("/api/tickets/{$ticket->id}/chat")->assertForbidden();
        $this->assertDatabaseMissing('conversations', ['ticket_id' => $ticket->id]);
    }
}
