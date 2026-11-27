<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Conversation;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\Unit;
use App\Models\User;
use App\Services\TicketChatBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketRealtimePollAndChannelTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unitA;

    private Unit $unitB;

    private SubUnit $subUnitA;

    private User $requester;

    private User $otherUser;

    private Admin $operatorA;

    private Admin $operatorB;

    private Admin $creatorAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Koordinator', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'admin']);

        $this->unitA = Unit::create(['nama_unit' => 'Unit IT', 'deskripsi' => 'IT', 'aktif' => true]);
        $this->unitB = Unit::create(['nama_unit' => 'Unit Keuangan', 'deskripsi' => 'Keuangan', 'aktif' => true]);

        $this->subUnitA = SubUnit::create([
            'unit_id' => $this->unitA->id,
            'nama_layanan' => 'Jaringan & Server',
            'deskripsi' => 'Layanan IT',
            'aktif' => true,
        ]);

        $this->requester = User::factory()->create([
            'username' => 'pemohon_rt',
            'email' => 'pemohon_rt@example.com',
        ]);

        $this->otherUser = User::factory()->create([
            'username' => 'user_lain_rt',
            'email' => 'user_lain_rt@example.com',
        ]);

        $this->operatorA = Admin::factory()->create([
            'username' => 'op_it_rt',
            'email' => 'op_it_rt@example.com',
        ]);
        $this->operatorA->assignRole('Operator');
        $this->operatorA->units()->attach($this->unitA->id);

        $this->operatorB = Admin::factory()->create([
            'username' => 'op_keu_rt',
            'email' => 'op_keu_rt@example.com',
        ]);
        $this->operatorB->assignRole('Operator');
        $this->operatorB->units()->attach($this->unitB->id);

        $this->creatorAdmin = Admin::factory()->create([
            'username' => 'creator_keu_rt',
            'email' => 'creator_keu_rt@example.com',
        ]);
        $this->creatorAdmin->assignRole('Operator');
        $this->creatorAdmin->units()->attach($this->unitB->id);
    }

    public function test_admin_poll_logs_returns_ordered_logs_for_authorized_admins_and_forbids_other_unit(): void
    {
        $ticket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->creatorAdmin->id,
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'assigned_admin_id' => $this->operatorA->id,
            'form_data' => ['judul' => 'Uji Polling Admin'],
            'status' => 'on_proses',
            'priority' => 'Sedang',
        ]);

        TicketLog::createQuietly([
            'ticket_id' => $ticket->id,
            'admin_id' => null,
            'aksi' => 'dibuat',
            'catatan' => 'Tiket dibuat',
            'timestamp' => now()->subMinutes(10),
        ]);

        $replyLog = TicketLog::createQuietly([
            'ticket_id' => $ticket->id,
            'admin_id' => $this->operatorA->id,
            'aksi' => 'balasan',
            'catatan' => 'Pesan sudah diedit',
            'is_edited' => true,
            'edited_at' => now()->subMinute(),
            'timestamp' => now()->subMinutes(5),
        ]);

        TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_log_id' => $replyLog->id,
            'file_path' => "ticket-attachments/{$ticket->id}/lampiran.xlsx",
            'original_name' => 'lampiran.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_size' => 2048,
            'wajib' => false,
        ]);

        // Unit operator gets 200 with newest-first chronological logs + edit & attachment metadata
        $res = $this->actingAs($this->operatorA, 'admin')
            ->getJson(route('admin.tiket.logs.poll', $ticket));

        $res->assertOk()
            ->assertJsonPath('status', 'on_proses')
            ->assertJsonPath('assigned_admin_id', $this->operatorA->id)
            ->assertJsonCount(2, 'logs')
            ->assertJsonPath('logs.0.id', $replyLog->id)
            ->assertJsonPath('logs.0.is_edited', true)
            ->assertJsonPath('logs.0.admin.id', $this->operatorA->id)
            ->assertJsonPath('logs.0.attachments.0.original_name', 'lampiran.xlsx')
            ->assertJsonPath('logs.1.aksi', 'dibuat')
            ->assertJsonStructure([
                'status',
                'assigned_admin_id',
                'updated_at',
                'logs' => [
                    '*' => ['id', 'ticket_id', 'admin_id', 'aksi', 'catatan', 'is_edited', 'edited_at', 'timestamp', 'admin', 'attachments'],
                ],
            ]);

        // Creator admin (outside unitA, but created_by_admin_id matches) also gets 200
        $this->actingAs($this->creatorAdmin, 'admin')
            ->getJson(route('admin.tiket.logs.poll', $ticket))
            ->assertOk()
            ->assertJsonCount(2, 'logs');

        // Unrelated unit admin gets 403
        $this->actingAs($this->operatorB, 'admin')
            ->getJson(route('admin.tiket.logs.poll', $ticket))
            ->assertForbidden();
        // Cross-unit role `'Admin'` viewer (`!isAccessibleBy`) gets 200 with system logs only (no `balasan`),
        // while still returning 403 on private WebSocket channel `private-ticket.{id}`
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'admin']);
        $crossUnitAdminViewer = Admin::factory()->create([
            'username' => 'admin_viewer_cross',
            'email' => 'admin_viewer_cross@example.com',
        ]);
        $crossUnitAdminViewer->assignRole('Admin');

        $viewerRes = $this->actingAs($crossUnitAdminViewer, 'admin')
            ->getJson(route('admin.tiket.logs.poll', $ticket));

        $viewerRes->assertOk()
            ->assertJsonPath('status', 'on_proses')
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('logs.0.aksi', 'dibuat');

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher' => [
                'driver' => 'pusher',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => ['cluster' => 'mt1', 'useTLS' => false],
            ],
        ]);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');

        auth('web')->logout();
        $this->actingAs($crossUnitAdminViewer, 'admin')
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-ticket.{$ticket->id}"])
            ->assertForbidden();
    }

    public function test_user_poll_logs_allows_owner_and_dual_account_user_and_forbids_other_user(): void
    {
        $ownedTicket = Ticket::create([
            'user_id' => $this->requester->id,
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'form_data' => ['judul' => 'Uji Polling User'],
            'status' => 'open',
            'priority' => 'Sedang',
        ]);

        TicketChatBridgeService::createReplyLog($ownedTicket, $this->operatorA, 'Balasan dari operator');

        $this->actingAs($this->requester)
            ->getJson(route('tiket.logs.poll', $ownedTicket))
            ->assertOk()
            ->assertJsonPath('status', 'open')
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('logs.0.catatan', 'Balasan dari operator');

        $this->actingAs($this->otherUser)
            ->getJson(route('tiket.logs.poll', $ownedTicket))
            ->assertForbidden();

        // Dual-account ticket created by creatorAdmin (`user_id = null`) accessible to User with matching email
        $dualUser = User::factory()->create([
            'username' => 'creator_keu_web',
            'email' => $this->creatorAdmin->email,
        ]);

        $adminSubmittedTicket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->creatorAdmin->id,
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'form_data' => ['judul' => 'Tiket Dual Account'],
            'status' => 'open',
            'priority' => 'Sedang',
        ]);

        TicketChatBridgeService::createReplyLog($adminSubmittedTicket, $this->operatorA, 'Update dual account');

        $this->actingAs($dualUser)
            ->getJson(route('tiket.logs.poll', $adminSubmittedTicket))
            ->assertOk()
            ->assertJsonPath('logs.0.catatan', 'Update dual account');

        $this->actingAs($this->otherUser)
            ->getJson(route('tiket.logs.poll', $adminSubmittedTicket))
            ->assertForbidden();
    }

    public function test_broadcast_channel_auth_supports_owner_dual_account_admin_and_dual_guard_sessions(): void
    {
        // Use real Pusher broadcaster in test so Broadcast::channel() callbacks actually execute.
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher' => [
                'driver' => 'pusher',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => [
                    'cluster' => 'mt1',
                    'useTLS' => false,
                ],
            ],
        ]);
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');

        $dualUser = User::factory()->create([
            'username' => 'dual_owner_user',
            'email' => $this->creatorAdmin->email,
        ]);

        $ticket = Ticket::create([
            'user_id' => $this->requester->id,
            'created_by_admin_id' => $this->creatorAdmin->id,
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'form_data' => ['judul' => 'Uji Auth Channel'],
            'status' => 'open',
            'priority' => 'Sedang',
        ]);

        $conversation = Conversation::create([
            'type' => 'ticket',
            'ticket_id' => $ticket->id,
            'user_id' => $this->requester->id,
        ]);

        $ticketChannel = "private-ticket.{$ticket->id}";
        $convChannel = "private-chat.conversation.{$conversation->id}";

        // Owner (`web`), dual-account (`web`), and authorized admin (`admin`) succeed
        $this->actingAs($this->requester, 'web')
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $ticketChannel])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($dualUser, 'web')
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $ticketChannel])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($this->operatorA, 'admin')
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $ticketChannel])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        // Clear guards before single-guard negative assertions so prior actingAs() doesn't leak.
        auth('web')->logout();
        auth('admin')->logout();

        // Unauthorized single-guard users/admins are rejected (403)
        $this->actingAs($this->otherUser, 'web')
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $ticketChannel])
            ->assertForbidden();

        auth('web')->logout();
        auth('admin')->logout();

        $this->actingAs($this->operatorB, 'admin')
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $ticketChannel])
            ->assertForbidden();

        // Dual-guard session: non-owner `web` user + authorized `admin` logged in simultaneously -> 200
        $this->actingAs($this->otherUser, 'web')
            ->actingAs($this->operatorA, 'admin')
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $ticketChannel])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($this->otherUser, 'web')
            ->actingAs($this->operatorA, 'admin')
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $convChannel])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        // Dual-guard session where NEITHER `web` nor `admin` has access -> 403
        $this->actingAs($this->otherUser, 'web')
            ->actingAs($this->operatorB, 'admin')
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $ticketChannel])
            ->assertForbidden();

        $this->actingAs($this->otherUser, 'web')
            ->actingAs($this->operatorB, 'admin')
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $convChannel])
            ->assertForbidden();
    }
}
