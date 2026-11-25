<?php

namespace Tests\Feature;

use App\Events\ChatMessageEdited;
use App\Events\TicketMessageEdited;
use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketLog;
use App\Models\Unit;
use App\Models\User;
use App\Services\TicketChatBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketCommentEditTest extends TestCase
{
    use RefreshDatabase;

    private function makeTicketFixture(?User $user = null, ?Admin $admin = null, string $status = 'on_proses'): array
    {
        $user ??= User::factory()->create();
        $admin ??= Admin::factory()->create();

        $unit = Unit::create(['nama_unit' => 'IT Support', 'is_aktif' => true]);
        $subUnit = SubUnit::create([
            'unit_id' => $unit->id,
            'nama_layanan' => 'Kendala Jaringan',
            'is_aktif' => true,
        ]);

        $admin->units()->syncWithoutDetaching([$unit->id]);

        $ticket = Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $subUnit->id,
            'assigned_admin_id' => $admin->id,
            'form_data' => [],
            'status' => $status,
            'priority' => 'Sedang',
        ]);

        return [$user, $admin, $unit, $ticket];
    }

    private function createLinkedReply(Ticket $ticket, ?Admin $admin, string $body): array
    {
        $log = TicketChatBridgeService::createReplyLog($ticket, $admin, $body);
        $message = TicketChatBridgeService::syncTicketReplyToChat($ticket, $log);

        return [$log, $message];
    }

    public function test_admin_can_edit_own_ticket_comment_and_sync_to_linked_chat_message(): void
    {
        Event::fake([TicketMessageEdited::class, ChatMessageEdited::class]);

        [$user, $admin, , $ticket] = $this->makeTicketFixture();
        [$log, $message] = $this->createLinkedReply($ticket, $admin, 'Komentar awal admin');

        $response = $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.tiket.reply.update', [$ticket->id, $log->id]), [
                'catatan' => 'Komentar admin diperbarui',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('log.catatan', 'Komentar admin diperbarui')
            ->assertJsonPath('log.is_edited', true);

        $this->assertDatabaseHas('ticket_logs', [
            'id' => $log->id,
            'catatan' => 'Komentar admin diperbarui',
            'is_edited' => true,
        ]);
        $this->assertNotNull($log->fresh()->edited_at);

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'body' => 'Komentar admin diperbarui',
            'is_edited' => true,
        ]);
        $this->assertNotNull($message->fresh()->edited_at);

        Event::assertDispatched(TicketMessageEdited::class, fn ($e) => $e->ticketId === $ticket->id && $e->log['id'] === $log->id);
        Event::assertDispatched(ChatMessageEdited::class, fn ($e) => $e->messageId === $message->id);
    }

    public function test_user_can_edit_own_ticket_comment_and_sync_to_linked_chat_message(): void
    {
        Event::fake([TicketMessageEdited::class, ChatMessageEdited::class]);

        [$user, $admin, , $ticket] = $this->makeTicketFixture();
        [$log, $message] = $this->createLinkedReply($ticket, null, 'Komentar awal user');

        $response = $this->actingAs($user)
            ->patchJson(route('tiket.reply.update', [$ticket->id, $log->id]), [
                'catatan' => 'Komentar user diperbarui',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('log.catatan', 'Komentar user diperbarui')
            ->assertJsonPath('log.is_edited', true);

        $this->assertDatabaseHas('ticket_logs', [
            'id' => $log->id,
            'catatan' => 'Komentar user diperbarui',
            'is_edited' => true,
        ]);

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'body' => 'Komentar user diperbarui',
            'is_edited' => true,
        ]);

        Event::assertDispatched(TicketMessageEdited::class);
        Event::assertDispatched(ChatMessageEdited::class);
    }

    public function test_admin_cannot_edit_other_admins_or_users_comment(): void
    {
        [$user, $adminA, $unit, $ticket] = $this->makeTicketFixture();
        $adminB = Admin::factory()->create();
        $adminB->units()->syncWithoutDetaching([$unit->id]);

        [$adminALog] = $this->createLinkedReply($ticket, $adminA, 'Punya Admin A');
        [$userLog] = $this->createLinkedReply($ticket, null, 'Punya User');

        $this->actingAs($adminB, 'admin')
            ->patchJson(route('admin.tiket.reply.update', [$ticket->id, $adminALog->id]), [
                'catatan' => 'Bajakan',
            ])
            ->assertForbidden();

        $this->actingAs($adminB, 'admin')
            ->patchJson(route('admin.tiket.reply.update', [$ticket->id, $userLog->id]), [
                'catatan' => 'Bajakan user',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('ticket_logs', ['id' => $adminALog->id, 'catatan' => 'Punya Admin A', 'is_edited' => false]);
        $this->assertDatabaseHas('ticket_logs', ['id' => $userLog->id, 'catatan' => 'Punya User', 'is_edited' => false]);
    }

    public function test_user_cannot_edit_admin_comment_or_other_users_ticket_comment(): void
    {
        [$userA, $admin, , $ticketA] = $this->makeTicketFixture();
        $userB = User::factory()->create();

        [$adminLog] = $this->createLinkedReply($ticketA, $admin, 'Punya Admin');
        [$userALog] = $this->createLinkedReply($ticketA, null, 'Punya User A');

        $this->actingAs($userA)
            ->patchJson(route('tiket.reply.update', [$ticketA->id, $adminLog->id]), [
                'catatan' => 'Coba edit admin',
            ])
            ->assertForbidden();

        $this->actingAs($userB)
            ->patchJson(route('tiket.reply.update', [$ticketA->id, $userALog->id]), [
                'catatan' => 'Coba edit tiket lain',
            ])
            ->assertForbidden();
    }

    public function test_editing_log_from_another_ticket_returns_not_found(): void
    {
        [$user, $admin, , $ticket1] = $this->makeTicketFixture();
        [, , , $ticket2] = $this->makeTicketFixture($user, $admin);

        [$logTicket2] = $this->createLinkedReply($ticket2, $admin, 'Log tiket 2');
        [$userLogTicket2] = $this->createLinkedReply($ticket2, null, 'User log tiket 2');

        $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.tiket.reply.update', [$ticket1->id, $logTicket2->id]), [
                'catatan' => 'Salah tiket',
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->patchJson(route('tiket.reply.update', [$ticket1->id, $userLogTicket2->id]), [
                'catatan' => 'Salah tiket',
            ])
            ->assertNotFound();
    }

    public function test_system_logs_closed_tickets_blank_input_and_deleted_messages_are_rejected(): void
    {
        [$user, $admin, , $ticket] = $this->makeTicketFixture();

        $systemLog = TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $admin->id,
            'aksi' => 'on_proses',
            'catatan' => 'Sedang diproses',
            'timestamp' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.tiket.reply.update', [$ticket->id, $systemLog->id]), [
                'catatan' => 'Ubah log sistem',
            ])
            ->assertStatus(422);

        [$replyLog, $linkedMsg] = $this->createLinkedReply($ticket, $admin, 'Asli');

        $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.tiket.reply.update', [$ticket->id, $replyLog->id]), [
                'catatan' => '   ',
            ])
            ->assertStatus(422);

        $linkedMsg->delete();

        $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.tiket.reply.update', [$ticket->id, $replyLog->id]), [
                'catatan' => 'Setelah dihapus',
            ])
            ->assertStatus(422);

        $linkedMsg->restore();

        foreach (['solve', 'selesai', 'dibatalkan', 'reject'] as $closedStatus) {
            $ticket->update(['status' => $closedStatus]);

            $this->actingAs($admin, 'admin')
                ->patchJson(route('admin.tiket.reply.update', [$ticket->id, $replyLog->id]), [
                    'catatan' => 'Edit saat tutup',
                ])
                ->assertStatus(422);
        }
    }

    public function test_web_chat_edit_in_ticket_room_works_after_read_and_syncs_ticket_log(): void
    {
        [$user, $admin, , $ticket] = $this->makeTicketFixture();
        [$userLog, $userMsg] = $this->createLinkedReply($ticket, null, 'Pesan user awal');
        [$adminLog, $adminMsg] = $this->createLinkedReply($ticket, $admin, 'Pesan admin awal');

        // Mark both messages as read by the opposite party.
        MessageRead::create([
            'message_id' => $userMsg->id,
            'user_type' => Admin::class,
            'user_id' => $admin->id,
            'read_at' => now(),
        ]);
        MessageRead::create([
            'message_id' => $adminMsg->id,
            'user_type' => User::class,
            'user_id' => $user->id,
            'read_at' => now(),
        ]);

        // Ticket room allows editing even after read, and syncs back to ticket_logs.
        $this->actingAs($user)
            ->putJson(route('chat.messages.update', $userMsg->id), ['body' => 'Pesan user direvisi'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('messages', [
            'id' => $userMsg->id,
            'body' => 'Pesan user direvisi',
            'is_edited' => true,
        ]);
        $this->assertDatabaseHas('ticket_logs', [
            'id' => $userLog->id,
            'catatan' => 'Pesan user direvisi',
            'is_edited' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->putJson(route('admin.chat.messages.update', $adminMsg->id), ['body' => 'Pesan admin direvisi'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('ticket_logs', [
            'id' => $adminLog->id,
            'catatan' => 'Pesan admin direvisi',
            'is_edited' => true,
        ]);

        // Closed ticket rejects edits in ticket chat room.
        $ticket->update(['status' => 'solve']);

        $this->actingAs($user)
            ->putJson(route('chat.messages.update', $userMsg->id), ['body' => 'Edit setelah selesai'])
            ->assertStatus(422);

        // Non-ticket direct chat preserves read-receipt lock.
        $directConv = Conversation::create([
            'type' => 'user_admin_direct',
            'user_id' => $user->id,
            'admin_one_id' => $admin->id,
            'last_message_at' => now(),
        ]);
        $directMsg = Message::create([
            'conversation_id' => $directConv->id,
            'sender_type' => User::class,
            'sender_id' => $user->id,
            'body' => 'Direct awal',
        ]);
        MessageRead::create([
            'message_id' => $directMsg->id,
            'user_type' => Admin::class,
            'user_id' => $admin->id,
            'read_at' => now(),
        ]);

        $this->actingAs($user)
            ->putJson(route('chat.messages.update', $directMsg->id), ['body' => 'Direct edit'])
            ->assertStatus(422);
    }

    public function test_mobile_api_can_edit_ticket_reply_and_chat_message_with_two_way_sync(): void
    {
        [$user, $admin, , $ticket] = $this->makeTicketFixture();
        $otherUser = User::factory()->create();

        [$userLog, $userMsg] = $this->createLinkedReply($ticket, null, 'Mobile awal');
        [$adminLog, $adminMsg] = $this->createLinkedReply($ticket, $admin, 'Admin mobile awal');

        Sanctum::actingAs($user);

        // Edit via ticket reply endpoint
        $this->patchJson("/api/tickets/{$ticket->id}/reply/{$userLog->id}", [
            'catatan' => 'Mobile via reply edit',
        ])->assertOk()
            ->assertJsonPath('data.note', 'Mobile via reply edit')
            ->assertJsonPath('data.is_edited', true);

        $this->assertDatabaseHas('messages', [
            'id' => $userMsg->id,
            'body' => 'Mobile via reply edit',
            'is_edited' => true,
        ]);

        // Edit via API chat message endpoint
        $this->putJson("/api/chat/messages/{$userMsg->id}", [
            'body' => 'Mobile via chat edit',
        ])->assertOk()
            ->assertJsonPath('message.body', 'Mobile via chat edit')
            ->assertJsonPath('message.is_edited', true);

        $this->assertDatabaseHas('ticket_logs', [
            'id' => $userLog->id,
            'catatan' => 'Mobile via chat edit',
            'is_edited' => true,
        ]);

        // GET /api/tickets/{id} reflects adminId, is_edited, edited_at & updated note
        $logsPayload = collect($this->getJson("/api/tickets/{$ticket->id}")->assertOk()->json('data.logs'));
        $editedEntry = $logsPayload->firstWhere('id', $userLog->id);
        $this->assertSame('Mobile via chat edit', $editedEntry['note'] ?? null);
        $this->assertNull($editedEntry['adminId'] ?? null);
        $this->assertTrue($editedEntry['is_edited'] ?? false);
        $this->assertNotNull($editedEntry['edited_at'] ?? null);

        $adminShowEntry = $logsPayload->firstWhere('id', $adminLog->id);
        $this->assertSame($admin->id, $adminShowEntry['adminId'] ?? null);
        $this->assertFalse($adminShowEntry['is_edited'] ?? true);
        $this->assertNull($adminShowEntry['edited_at'] ?? null);

        // Unauthorized edits rejected (403)
        $this->patchJson("/api/tickets/{$ticket->id}/reply/{$adminLog->id}", ['catatan' => 'Ilegal'])
            ->assertForbidden();
        $this->putJson("/api/chat/messages/{$adminMsg->id}", ['body' => 'Ilegal'])
            ->assertForbidden();

        Sanctum::actingAs($otherUser);
        $this->patchJson("/api/tickets/{$ticket->id}/reply/{$userLog->id}", ['catatan' => 'Intruder'])
            ->assertForbidden();

        // Admin API reply + edit includes adminId, is_edited & edited_at
        Sanctum::actingAs($admin);
        $postedReply = $this->postJson("/api/tickets/{$ticket->id}/reply", [
            'catatan' => 'Balasan baru admin',
        ])->assertCreated()
            ->assertJsonPath('data.adminId', $admin->id)
            ->assertJsonPath('data.is_edited', false)
            ->assertJsonPath('data.edited_at', null);

        $newLogId = (int) $postedReply->json('data.id');
        $this->patchJson("/api/tickets/{$ticket->id}/reply/{$newLogId}", [
            'catatan' => 'Balasan baru admin diedit',
        ])->assertOk()
            ->assertJsonPath('data.adminId', $admin->id)
            ->assertJsonPath('data.is_edited', true);

        $editRes = $this->patchJson("/api/tickets/{$ticket->id}/reply/{$adminLog->id}", [
            'catatan' => 'Admin API edit',
        ])->assertOk()
            ->assertJsonPath('data.note', 'Admin API edit')
            ->assertJsonPath('data.adminId', $admin->id)
            ->assertJsonPath('data.is_edited', true);
        $this->assertNotNull($editRes->json('data.edited_at'));

        $ticket->update(['status' => 'solve']);

        $this->patchJson("/api/tickets/{$ticket->id}/reply/{$adminLog->id}", [
            'catatan' => 'Edit setelah solve',
        ])->assertStatus(422);

        $this->putJson("/api/chat/messages/{$adminMsg->id}", [
            'body' => 'Edit setelah solve',
        ])->assertStatus(422);
    }

    public function test_revoked_unit_admin_cannot_edit_ticket_chat_message_via_web_or_api(): void
    {
        [$user, $assignedAdmin, $unit, $ticket] = $this->makeTicketFixture();
        $unitAdmin = Admin::factory()->create();
        $unitAdmin->units()->attach($unit->id);

        [$log, $message] = $this->createLinkedReply($ticket, $unitAdmin, 'Komentar sebelum dicabut');

        // Revoke unit access from the non-assigned unit admin.
        $unitAdmin->units()->detach();

        $this->actingAs($unitAdmin, 'admin')
            ->putJson(route('admin.chat.messages.update', $message->id), [
                'body' => 'Edit setelah dicabut',
            ])
            ->assertForbidden();

        Sanctum::actingAs($unitAdmin);
        $this->putJson("/api/chat/messages/{$message->id}", [
            'body' => 'Edit API setelah dicabut',
        ])->assertForbidden();

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'body' => 'Komentar sebelum dicabut',
            'is_edited' => false,
        ]);
        $this->assertDatabaseHas('ticket_logs', [
            'id' => $log->id,
            'catatan' => 'Komentar sebelum dicabut',
            'is_edited' => false,
        ]);
    }
}
