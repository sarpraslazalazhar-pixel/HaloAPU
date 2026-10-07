<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SubUnit;
use App\Models\SystemConfig;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_storage_path_traversal_is_blocked(): void
    {
        $response = $this->get('/storage/../../.env');
        $this->assertTrue(in_array($response->status(), [403, 404]));
    }
    public function test_chat_attachment_idor_is_blocked(): void
    {
        Storage::fake('public');

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::create([
            'user_id' => $userA->id,
            'type' => 'user_support',
            'last_message_at' => now(),
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => User::class,
            'sender_id' => $userA->id,
            'body' => 'Secret message',
        ]);

        Storage::disk('public')->put('chat_attachments/secret.pdf', 'secret content');

        $attachment = ChatAttachment::create([
            'message_id' => $message->id,
            'file_name' => 'secret.pdf',
            'file_path' => 'chat_attachments/secret.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1234,
        ]);

        // User B attempts to download User A's attachment
        $response = $this->actingAs($userB)->get(route('chat.download', $attachment->id));
        $response->assertStatus(403);
    }

    public function test_admin_ticket_attachment_idor_is_blocked(): void
    {
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'admin']);

        $operator = Admin::create([
            'username' => 'operator1',
            'email' => 'operator1@test.com',
            'password' => bcrypt('password'),
        ]);
        $operator->assignRole('Operator');


        $unit = Unit::create(['nama_unit' => 'Unit Test', 'aktif' => true]);
        $subUnit = SubUnit::create(['unit_id' => $unit->id, 'nama_layanan' => 'Layanan Test']);

        $user = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $subUnit->id,
            'form_data' => [],
            'status' => 'open',
            'assigned_admin_id' => null, // Not assigned to $operator
        ]);

        Storage::disk('public')->put("ticket-attachments/{$ticket->id}/secret.pdf", 'classified');

        $attachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'file_path' => "ticket-attachments/{$ticket->id}/secret.pdf",
            'original_name' => 'secret.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 500,
        ]);

        // Operator not assigned to this ticket tries to download attachment
        $response = $this->actingAs($operator, 'admin')
            ->get(route('admin.tiket.download', $attachment->id));

        $response->assertStatus(403);
    }

    public function test_tv_dashboard_token_and_units(): void
    {
        SystemConfig::setValue('tv_dashboard_token', 'Valid-Secret-Token-123');

        // Invalid token
        $responseInvalid = $this->get('/tv?token=Wrong-Token');
        $responseInvalid->assertStatus(403);

        // Missing token
        $responseMissing = $this->get('/tv');
        $responseMissing->assertStatus(403);

        // Valid token
        $responseValid = $this->get('/tv?token=Valid-Secret-Token-123');
        $responseValid->assertStatus(200);
        $responseValid->assertInertia(fn ($page) => $page
            ->component('Tv/Index')
            ->has('units')
            ->has('stats')
        );
    }

    public function test_privilege_escalation_to_superadmin_blocked(): void
    {
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'admin']);
        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'akses-manajemen-akun', 'guard_name' => 'admin']);

        $operator = Admin::create([
            'username' => 'operator_evil',
            'email' => 'operator_evil@test.com',
            'password' => bcrypt('password'),
        ]);
        $operator->assignRole('Operator');
        $operator->givePermissionTo($perm);

        // Non-superadmin operator attempts to create a superadmin
        $response = $this->actingAs($operator, 'admin')
            ->post(route('admin.manajemen-operator.store'), [
                'username' => 'new_superadmin',
                'name' => 'New Super',
                'email' => 'new_super@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => 'superadmin',
            ]);

        $response->assertStatus(403);
    }
}
