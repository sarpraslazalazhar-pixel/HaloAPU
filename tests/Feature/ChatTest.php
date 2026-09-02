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

    public function test_user_can_access_chat_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('chat.index'));

        $response->assertStatus(200);
        $this->assertDatabaseHas('conversations', [
            'type' => 'public_global',
        ]);
    }

    public function test_conversation_auto_created_per_user(): void
    {
        $user = User::factory()->create();

        // Accessing chat page auto-creates the conversation
        $this->actingAs($user)->get(route('chat.index'));

        $this->assertDatabaseHas('conversations', [
            'type' => 'public_global',
        ]);

        // Accessing again should NOT create a second conversation
        $this->actingAs($user)->get(route('chat.index'));
        $this->assertDatabaseCount('conversations', 1);
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

