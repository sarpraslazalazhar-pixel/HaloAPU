<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\RoomVehicleBooking;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\BrowserNotification;
use App\Services\TicketWorkQueue;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminSelfTicketCancelTest extends TestCase
{
    use RefreshDatabase;

    private Unit $targetUnit;
    private SubUnit $targetSubUnit;
    private Admin $requesterAdmin;
    private Admin $targetUnitAdmin;
    private Admin $otherAdmin;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class]);
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'admin']);

        $this->targetUnit = Unit::create([
            'nama_unit' => 'IT Support',
            'deskripsi' => 'Unit IT',
            'aktif' => true,
        ]);

        $this->targetSubUnit = SubUnit::create([
            'unit_id' => $this->targetUnit->id,
            'nama_layanan' => 'Perbaikan Perangkat',
            'deskripsi' => 'Perbaikan',
            'aktif' => true,
        ]);

        $this->requesterAdmin = Admin::factory()->create([
            'username' => 'admin_pengaju',
            'email' => 'pengaju@example.test',
            'name' => 'Admin Pengaju',
        ]);
        $this->requesterAdmin->assignRole('Admin');

        $this->targetUnitAdmin = Admin::factory()->create([
            'username' => 'operator_it',
            'email' => 'operator_it@example.test',
            'name' => 'Operator IT',
        ]);
        $this->targetUnitAdmin->assignRole('Admin');
        $this->targetUnitAdmin->units()->attach($this->targetUnit->id);

        $this->otherAdmin = Admin::factory()->create([
            'username' => 'admin_lain',
            'email' => 'lain@example.test',
        ]);
        $this->otherAdmin->assignRole('Admin');

        $this->otherUser = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createOwnTicket(array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_id' => null,
            'created_by_admin_id' => $this->requesterAdmin->id,
            'unit_id' => $this->targetUnit->id,
            'sub_unit_id' => $this->targetSubUnit->id,
            'form_data' => ['Keluhan' => 'Printer bermasalah'],
            'status' => 'open',
        ], $overrides));
    }

    public function test_regular_admin_can_cancel_own_tickets_across_non_terminal_statuses_and_promotes_queue_and_notifies_unit_admins(): void
    {
        Notification::fake();

        // 1. Status open (di-attach juga ke unit supaya ikut uji no-self-notification)
        $this->requesterAdmin->units()->attach($this->targetUnit->id);

        $openTicket = $this->createOwnTicket(['status' => 'open']);
        $this->actingAs($this->requesterAdmin, 'admin')
            ->patch(route('admin.tiket.cancel', $openTicket))
            ->assertRedirect();
        $this->assertSame('dibatalkan', $openTicket->fresh()->status);

        Notification::assertSentTo($this->targetUnitAdmin, BrowserNotification::class);
        Notification::assertNotSentTo($this->requesterAdmin, BrowserNotification::class);

        // 2. Status pending luar jam kerja (assignment_resume_status = 'open')
        $outOfHoursTicket = $this->createOwnTicket([
            'status' => 'pending',
            'assignment_resume_status' => 'open',
            'assignment_queued_at' => null,
        ]);
        $this->actingAs($this->requesterAdmin, 'admin')
            ->patch(route('admin.tiket.cancel', $outOfHoursTicket))
            ->assertRedirect();
        $this->assertSame('dibatalkan', $outOfHoursTicket->fresh()->status);
        $this->assertNull($outOfHoursTicket->fresh()->assignment_resume_status);

        // 3. Status pending dalam antrean operator + status on_proses yang memegang slot
        $activeOwn = $this->createOwnTicket(['status' => 'on_proses']);
        TicketSlaTracking::create([
            'ticket_id' => $activeOwn->id,
            'sla_response_deadline' => now()->addHour(),
            'sla_resolution_deadline' => now()->addHours(4),
        ]);
        TicketWorkQueue::assign($activeOwn, $this->targetUnitAdmin, $this->targetUnitAdmin);

        $queuedTicket = Ticket::create([
            'user_id' => $this->otherUser->id,
            'unit_id' => $this->targetUnit->id,
            'sub_unit_id' => $this->targetSubUnit->id,
            'form_data' => ['Keluhan' => 'Antrean kedua'],
            'status' => 'open',
        ]);
        TicketSlaTracking::create([
            'ticket_id' => $queuedTicket->id,
            'sla_response_deadline' => now()->addHour(),
            'sla_resolution_deadline' => now()->addHours(4),
        ]);
        TicketWorkQueue::assign($queuedTicket, $this->targetUnitAdmin, $this->targetUnitAdmin);
        $this->assertSame('pending', $queuedTicket->fresh()->status);
        $this->assertNotNull($queuedTicket->fresh()->assignment_queued_at);

        // Antrean milik admin pengaju sendiri juga bisa dibatalkan langsung saat masih queued
        $queuedOwn = $this->createOwnTicket(['status' => 'open']);
        TicketSlaTracking::create([
            'ticket_id' => $queuedOwn->id,
            'sla_response_deadline' => now()->addHour(),
            'sla_resolution_deadline' => now()->addHours(4),
        ]);
        TicketWorkQueue::assign($queuedOwn, $this->targetUnitAdmin, $this->targetUnitAdmin);
        $this->assertSame('pending', $queuedOwn->fresh()->status);
        $this->assertNotNull($queuedOwn->fresh()->assignment_queued_at);

        $this->actingAs($this->requesterAdmin, 'admin')
            ->patch(route('admin.tiket.cancel', $queuedOwn))
            ->assertRedirect();
        $this->assertSame('dibatalkan', $queuedOwn->fresh()->status);
        $this->assertNull($queuedOwn->fresh()->assignment_queued_at);

        // Batalkan activeOwn (on_proses) -> harus jadi dibatalkan dan mempromosikan queuedTicket
        $this->actingAs($this->requesterAdmin, 'admin')
            ->patch(route('admin.tiket.cancel', $activeOwn))
            ->assertRedirect();
        $this->assertSame('dibatalkan', $activeOwn->fresh()->status);
        $this->assertSame('open', $queuedTicket->fresh()->status);
        $this->assertNull($queuedTicket->fresh()->assignment_queued_at);
    }

    public function test_admin_can_cancel_ticket_submitted_via_matching_user_account(): void
    {
        $matchingUser = User::factory()->create([
            'username' => $this->requesterAdmin->username,
            'email' => $this->requesterAdmin->email,
        ]);

        $ticket = Ticket::create([
            'user_id' => $matchingUser->id,
            'created_by_admin_id' => null,
            'unit_id' => $this->targetUnit->id,
            'sub_unit_id' => $this->targetSubUnit->id,
            'form_data' => ['Keluhan' => 'Diajukan dari portal user'],
            'status' => 'on_proses',
        ]);

        $this->actingAs($this->requesterAdmin, 'admin')
            ->patch(route('admin.tiket.cancel', $ticket))
            ->assertRedirect();

        $this->assertSame('dibatalkan', $ticket->fresh()->status);
    }

    public function test_admin_can_cancel_active_own_booking_and_is_rejected_when_booking_ended(): void
    {
        $activeBookingTicket = $this->createOwnTicket(['status' => 'on_proses']);
        $activeBooking = RoomVehicleBooking::create([
            'ticket_id' => $activeBookingTicket->id,
            'tipe' => 'Ruangan',
            'nama_aset' => 'Ruang Rapat A',
            'tanggal_mulai' => now()->addHour(),
            'tanggal_selesai' => now()->addHours(3),
            'status' => 'on_proses',
        ]);

        $this->actingAs($this->requesterAdmin, 'admin')
            ->patch(route('admin.tiket.cancel', $activeBookingTicket))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('dibatalkan', $activeBookingTicket->fresh()->status);
        $this->assertSame('dibatalkan', $activeBooking->fresh()->status);

        // Booking yang masa peminjamannya sudah berakhir ditolak
        $pastBookingTicket = $this->createOwnTicket(['status' => 'open']);
        $pastBooking = RoomVehicleBooking::create([
            'ticket_id' => $pastBookingTicket->id,
            'tipe' => 'Ruangan',
            'nama_aset' => 'Ruang Rapat B',
            'tanggal_mulai' => now()->subHours(4),
            'tanggal_selesai' => now()->subHour(),
            'status' => 'open',
        ]);

        $this->actingAs($this->requesterAdmin, 'admin')
            ->patch(route('admin.tiket.cancel', $pastBookingTicket))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('open', $pastBookingTicket->fresh()->status);
        $this->assertSame('open', $pastBooking->fresh()->status);
    }

    public function test_admin_cannot_cancel_own_ticket_in_terminal_statuses(): void
    {
        foreach (['selesai', 'solve', 'dibatalkan', 'reject'] as $status) {
            $ticket = $this->createOwnTicket(['status' => $status]);

            $this->actingAs($this->requesterAdmin, 'admin')
                ->patch(route('admin.tiket.cancel', $ticket))
                ->assertRedirect()
                ->assertSessionHas('error');

            $this->assertSame($status, $ticket->fresh()->status);
        }
    }

    public function test_idor_protection_prevents_regular_admin_cancelling_others_tickets(): void
    {
        $otherAdminTicket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->otherAdmin->id,
            'unit_id' => $this->targetUnit->id,
            'sub_unit_id' => $this->targetSubUnit->id,
            'form_data' => ['Keluhan' => 'Milik admin lain'],
            'status' => 'open',
        ]);

        $otherUserTicket = Ticket::create([
            'user_id' => $this->otherUser->id,
            'created_by_admin_id' => null,
            'unit_id' => $this->targetUnit->id,
            'sub_unit_id' => $this->targetSubUnit->id,
            'form_data' => ['Keluhan' => 'Milik user lain'],
            'status' => 'open',
        ]);

        $this->actingAs($this->requesterAdmin, 'admin')
            ->patch(route('admin.tiket.cancel', $otherAdminTicket))
            ->assertForbidden();

        $this->actingAs($this->requesterAdmin, 'admin')
            ->patch(route('admin.tiket.cancel', $otherUserTicket))
            ->assertForbidden();

        $this->assertSame('open', $otherAdminTicket->fresh()->status);
        $this->assertSame('open', $otherUserTicket->fresh()->status);
    }

    public function test_inertia_detail_and_index_saya_ajukan_expose_accurate_can_cancel_flags(): void
    {
        $outOfHoursTicket = $this->createOwnTicket([
            'status' => 'pending',
            'assignment_resume_status' => 'open',
            'created_at' => now()->subMinutes(1),
        ]);
        $inProgressTicket = $this->createOwnTicket([
            'status' => 'on_proses',
            'created_at' => now()->subMinutes(2),
        ]);
        $solvedTicket = $this->createOwnTicket([
            'status' => 'solve',
            'created_at' => now()->subMinutes(3),
        ]);
        $pastBookingTicket = $this->createOwnTicket([
            'status' => 'open',
            'created_at' => now()->subMinutes(4),
        ]);
        RoomVehicleBooking::create([
            'ticket_id' => $pastBookingTicket->id,
            'tipe' => 'Ruangan',
            'nama_aset' => 'Aula',
            'tanggal_mulai' => now()->subHours(3),
            'tanggal_selesai' => now()->subMinutes(10),
            'status' => 'open',
        ]);

        // Detail props
        $this->actingAs($this->requesterAdmin, 'admin')
            ->get(route('admin.tiket.show', $outOfHoursTicket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('isSubmittedByMe', true)
                ->where('canCancelTicket', true)
            );

        $this->actingAs($this->requesterAdmin, 'admin')
            ->get(route('admin.tiket.show', $inProgressTicket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCancelTicket', true));

        $this->actingAs($this->requesterAdmin, 'admin')
            ->get(route('admin.tiket.show', $solvedTicket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCancelTicket', false));

        $this->actingAs($this->requesterAdmin, 'admin')
            ->get(route('admin.tiket.show', $pastBookingTicket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCancelTicket', false));

        // Index tab saya_ajukan can_cancel flags
        $this->actingAs($this->requesterAdmin, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'saya_ajukan']))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($outOfHoursTicket, $inProgressTicket, $solvedTicket, $pastBookingTicket) {
                $page->where('currentTab', 'saya_ajukan');

                $byId = collect($page->toArray()['props']['tickets']['data'])->keyBy('id');
                $this->assertTrue((bool) $byId->get($outOfHoursTicket->id)['can_cancel']);
                $this->assertTrue((bool) $byId->get($inProgressTicket->id)['can_cancel']);
                $this->assertFalse((bool) $byId->get($solvedTicket->id)['can_cancel']);
                $this->assertFalse((bool) $byId->get($pastBookingTicket->id)['can_cancel']);
            });
    }

    public function test_api_admin_creator_can_cancel_own_pending_and_in_progress_tickets(): void
    {
        $pendingTicket = $this->createOwnTicket([
            'status' => 'pending',
            'assignment_resume_status' => 'open',
        ]);
        $inProgressTicket = $this->createOwnTicket([
            'status' => 'on_proses',
        ]);

        Sanctum::actingAs($this->requesterAdmin, ['*']);

        $this->patchJson("/api/tickets/{$pendingTicket->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'dibatalkan');
        $this->assertSame('dibatalkan', $pendingTicket->fresh()->status);

        $this->patchJson("/api/tickets/{$inProgressTicket->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'dibatalkan');
        $this->assertSame('dibatalkan', $inProgressTicket->fresh()->status);
    }
}
