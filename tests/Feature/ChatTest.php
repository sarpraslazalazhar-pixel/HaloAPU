<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\SubUnit;
use App\Models\User;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_access_chat_page_without_an_active_room(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('chat.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Chat/Index')
            ->where('activeConversationId', null)
            ->where('activeConversation', null)
        );
    }

    private function makeTicketFor(User $user): Ticket
    {
        $unit = Unit::create(['nama_unit' => 'IT', 'is_aktif' => true]);
        $subUnit = SubUnit::create([
            'unit_id' => $unit->id,
            'nama_layanan' => 'Jaringan',
            'is_aktif' => true,
        ]);

        return Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $subUnit->id,
            'form_data' => [],
            'status' => 'open',
            'priority' => 'Sedang',
        ]);
    }

    public function test_ticket_room_is_created_once_and_scoped_to_the_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = $this->makeTicketFor($user);

        $this->actingAs($user)->get(route('chat.index', ['ticket' => $ticket->id]));
        $this->actingAs($user)->get(route('chat.index', ['ticket' => $ticket->id]));

        $this->assertDatabaseHas('conversations', [
            'type' => 'ticket',
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_opening_a_ticket_room_returns_its_conversation_and_messages(): void
    {
        $user = User::factory()->create();
        $ticket = $this->makeTicketFor($user);

        $response = $this->actingAs($user)->get(route('chat.index', ['ticket' => $ticket->id]));

        $response->assertInertia(fn ($page) => $page
            ->component('Chat/Index')
            ->where('activeConversation.ticket_id', $ticket->id)
            ->has('activeMessages', 0)
        );
    }

    public function test_chat_page_lists_the_users_ticket_rooms_in_the_inbox(): void
    {
        $user = User::factory()->create();
        $ticket = $this->makeTicketFor($user);

        $response = $this->actingAs($user)->get(route('chat.index', ['ticket' => $ticket->id]));

        $response->assertInertia(fn ($page) => $page
            ->component('Chat/Index')
            ->has('conversations', 1)
            ->where('conversations.0.ticket.id', $ticket->id)
            ->where('conversations.0.type', 'ticket')
            ->where('conversations.0.unread_count', 0)
        );
    }

    public function test_admin_opens_a_ticket_room_from_the_ticket_page_link(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $ticket = $this->makeTicketFor($user);
        $ticket->update(['assigned_admin_id' => $admin->id]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.chat.index', ['user_id' => $user->id, 'ticket_id' => $ticket->id]));

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Chat/Index')
            ->where('activeConversation.ticket_id', $ticket->id)
        );

        $this->assertDatabaseHas('conversations', [
            'type' => 'ticket',
            'ticket_id' => $ticket->id,
        ]);
    }

    public function test_admin_message_in_a_ticket_room_stays_on_that_ticket(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $ticket = $this->makeTicketFor($user);
        $other = $this->makeTicketFor($user);
        $ticket->update(['assigned_admin_id' => $admin->id]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.chat.index', ['ticket_id' => $ticket->id]));

        $conversation = Conversation::where('type', 'ticket')->where('ticket_id', $ticket->id)->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.chat.messages.store', $conversation->id), ['body' => 'Sedang dicek ya'])
            ->assertOk();

        $message = Message::where('conversation_id', $conversation->id)->latest('id')->firstOrFail();

        $this->assertSame($ticket->id, $message->ticket_id);
        $this->assertNotSame($other->id, $message->ticket_id);
    }

    public function test_admin_outside_the_ticket_cannot_open_its_room(): void
    {
        $user = User::factory()->create();
        $owner = Admin::factory()->create();
        $outsider = Admin::factory()->create();
        $ticket = $this->makeTicketFor($user);
        $ticket->update(['assigned_admin_id' => $owner->id]);

        // Falls back to the admin's own rooms; the ticket room is never created.
        $this->actingAs($outsider, 'admin')
            ->get(route('admin.chat.index', ['ticket_id' => $ticket->id]))
            ->assertInertia(fn ($page) => $page->where('activeConversation.ticket_id', null));

        $this->assertDatabaseMissing('conversations', ['ticket_id' => $ticket->id]);
    }

    public function test_user_cannot_open_another_users_ticket_room(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $ticket = $this->makeTicketFor($owner);

        $this->actingAs($intruder)
            ->get(route('chat.index', ['ticket' => $ticket->id]))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->get(route('chat.tickets.room', $ticket->id))
            ->assertForbidden();
    }

    public function test_user_can_send_message_with_attachment_under_3mb(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $conversation = Conversation::create([
            'user_id' => $user->id,
            'last_message_at' => now(),
        ]);

        $file = UploadedFile::fake()->create('document.pdf', 2000, 'application/pdf');

        $response = $this->actingAs($user)->post(route('chat.messages.store', $conversation->id), [
            'body' => 'Pesan pengujian',
            'attachment' => $file,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'body' => 'Pesan pengujian',
        ]);

        $this->assertDatabaseHas('chat_attachments', [
            'file_name' => 'document.pdf',
        ]);
    }

    public function test_attachment_over_3mb_is_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $conversation = Conversation::create([
            'user_id' => $user->id,
            'last_message_at' => now(),
        ]);

        $file = UploadedFile::fake()->create('large.zip', 4000, 'application/zip');

        $response = $this->actingAs($user)->post(route('chat.messages.store', $conversation->id), [
            'body' => 'Pesan file besar',
            'attachment' => $file,
        ]);

        $response->assertSessionHasErrors(['attachment']);
    }

    public function test_unauthorized_user_cannot_send_message_in_other_user_conversation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversationA = Conversation::create([
            'user_id' => $userA->id,
            'last_message_at' => now(),
        ]);

        $response = $this->actingAs($userB)->post(route('chat.messages.store', $conversationA->id), [
            'body' => 'Pesan penyusup',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_can_attach_ticket_reference_in_message(): void
    {
        $user = User::factory()->create();
        $unit = Unit::create(['nama_unit' => 'Unit Layanan Test']);
        $subUnit = SubUnit::create(['unit_id' => $unit->id, 'nama_layanan' => 'Sub Unit Test']);

        $ticket = Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $subUnit->id,
            'form_data' => [],
            'status' => 'open',
        ]);

        $conversation = Conversation::create([
            'user_id' => $user->id,
            'last_message_at' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('chat.messages.store', $conversation->id), [
            'body' => 'Merujuk pada tiket ini',
            'ticket_id' => $ticket->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'ticket_id' => $ticket->id,
            'body' => 'Merujuk pada tiket ini',
        ]);
    }

    public function test_user_can_soft_delete_own_message(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'last_message_at' => now(),
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => User::class,
            'sender_id' => $user->id,
            'body' => 'Hapus pesan ini',
        ]);

        $response = $this->actingAs($user)->delete(route('chat.messages.destroy', $message->id));

        $response->assertStatus(200);
        $this->assertSoftDeleted('messages', ['id' => $message->id]);
    }

    public function test_admin_direct_conversation_is_auto_created_and_private(): void
    {
        $admin1 = Admin::factory()->create();
        $admin2 = Admin::factory()->create();
        $admin3 = Admin::factory()->create();

        // When Admin 1 accesses chat, it auto-creates direct convos with all other admins
        $this->actingAs($admin1, 'admin')->get(route('admin.chat.index'));

        $this->assertDatabaseHas('conversations', [
            'type' => 'admin_direct',
            'admin_one_id' => min($admin1->id, $admin2->id),
            'admin_two_id' => max($admin1->id, $admin2->id),
        ]);

        $this->assertDatabaseHas('conversations', [
            'type' => 'admin_direct',
            'admin_one_id' => min($admin1->id, $admin3->id),
            'admin_two_id' => max($admin1->id, $admin3->id),
        ]);

        $conversation = Conversation::where('type', 'admin_direct')
            ->where('admin_one_id', min($admin1->id, $admin2->id))
            ->where('admin_two_id', max($admin1->id, $admin2->id))
            ->first();

        // Admin 3 cannot view this conversation (policy test)
        $policy = new \App\Policies\ConversationPolicy();
        $this->assertTrue($policy->view($admin1, $conversation));
        $this->assertTrue($policy->view($admin2, $conversation));
        $this->assertFalse($policy->view($admin3, $conversation));
    }
}

