<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SlaConfig;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TicketWorkHoursNotification;
use App\Services\SlaCalculator;
use App\Services\TicketStatusService;
use App\Services\TicketWorkQueue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketQueueSwapTest extends TestCase
{
    use RefreshDatabase;

    private User $requesterA;
    private User $requesterB;
    private User $requesterC;
    private Admin $coordinator;
    private Admin $operator;
    private Admin $otherOperator;
    private Unit $unit;
    private SubUnit $subUnit;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Jakarta']);
        Notification::fake();

        $cache = new \ReflectionProperty(SlaConfig::class, 'thresholdCache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);

        $this->requesterA = User::factory()->create();
        $this->requesterB = User::factory()->create();
        $this->requesterC = User::factory()->create();
        $this->unit = Unit::create(['nama_unit' => 'IT Support', 'deskripsi' => 'IT', 'aktif' => true]);
        $this->subUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Perbaikan Jaringan',
            'deskripsi' => 'Jaringan',
            'aktif' => true,
            'is_monitored' => false,
        ]);

        $this->coordinator = $this->makeAdmin('swap_super', 'Super Admin');
        $this->operator = $this->makeAdmin('swap_operator', 'Operator');
        $this->otherOperator = $this->makeAdmin('swap_other', 'Operator');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeAdmin(string $username, string $role): Admin
    {
        $admin = Admin::create([
            'username' => $username,
            'email' => "{$username}@example.com",
            'name' => strtoupper($username),
            'password' => Hash::make('password'),
        ]);
        if (! Role::where('name', $role)->where('guard_name', 'admin')->exists()) {
            Role::create(['name' => $role, 'guard_name' => 'admin']);
        }
        $admin->assignRole($role);
        $admin->units()->attach($this->unit->id);

        return $admin;
    }

    private function createTicket(User $requester, string $priority = 'Sedang', string $status = 'open', ?int $adminId = null, ?array $window = null): Ticket
    {
        $ticket = Ticket::create([
            'user_id' => $requester->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['judul' => 'Tiket '.$priority],
            'status' => $status,
            'priority' => $priority,
            'assigned_admin_id' => $adminId,
        ]);

        (new SlaCalculator)->createTracking($ticket, $window);

        return $ticket->fresh('slaTracking');
    }

    public function test_coordinator_can_swap_urgent_queued_ticket_with_active_ticket_and_restore_displaced_first(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00'));

        // A: aktif on_proses dipegang operator.
        $ticketA = $this->createTicket($this->requesterA, 'Sedang', 'on_proses', $this->operator->id);

        // C: antrean normal masuk lebih dulu (09:01).
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:01:00'));
        $ticketC = $this->createTicket($this->requesterC, 'Sedang');
        TicketWorkQueue::assign($ticketC, $this->operator, $this->coordinator);

        // B: antrean Urgen berjadwal booking (09:02).
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:02:00'));
        $bookingWindow = [
            'start' => Carbon::parse('2026-07-21 09:00:00'),
            'end' => Carbon::parse('2026-07-21 17:00:00'),
        ];
        $ticketB = $this->createTicket($this->requesterB, 'Urgen', 'open', null, $bookingWindow);
        TicketWorkQueue::assign($ticketB, $this->operator, $this->coordinator);
        Notification::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-20 09:30:00'));
        $this->actingAs($this->coordinator, 'admin')
            ->post(route('admin.tiket.activate-from-queue', $ticketB->id))
            ->assertRedirect()
            ->assertSessionHas('success', 'Tiket antrean berhasil dijadikan tiket aktif.');

        $ticketA->refresh();
        $ticketB->refresh();
        $ticketC->refresh();

        // B menjadi aktif, SLA booking tetap terjaga.
        $this->assertSame('open', $ticketB->status);
        $this->assertNull($ticketB->assignment_queued_at);
        $this->assertNull($ticketB->assignment_resume_status);
        $this->assertNull($ticketB->slaTracking->paused_at);
        $this->assertSame('2026-07-21 17:00:00', $ticketB->slaTracking->sla_resolution_deadline->format('Y-m-d H:i:s'));

        // A dijeda ke antrean terdepan (sebelum C) dengan SLA paused dan status resume on_proses.
        $this->assertSame('pending', $ticketA->status);
        $this->assertSame('on_proses', $ticketA->assignment_resume_status);
        $this->assertNotNull($ticketA->assignment_queued_at);
        $this->assertNotNull($ticketA->slaTracking->paused_at);
        $this->assertTrue($ticketA->assignment_queued_at->lt($ticketC->assignment_queued_at));

        $payload = TicketWorkQueue::payload((int) $this->operator->id);
        $this->assertSame((int) $ticketB->id, $payload['active']['id']);
        $this->assertSame([(int) $ticketA->id, (int) $ticketC->id], array_column($payload['queue'], 'id'));

        // Notif terkirim ke operator (karena dipicu coordinator), pengaju B, dan pengaju A.
        Notification::assertSentTo($this->operator, TicketWorkHoursNotification::class, function ($n) use ($ticketB) {
            return (int) $n->ticket->id === (int) $ticketB->id
                && str_starts_with($n->toArray($this->operator)['title'], 'Giliran pengerjaan:');
        });
        Notification::assertSentTo($this->requesterB, TicketWorkHoursNotification::class, function ($n) use ($ticketB) {
            return (int) $n->ticket->id === (int) $ticketB->id
                && str_starts_with($n->toArray($this->requesterB)['title'], 'Giliran pengerjaan:');
        });
        Notification::assertSentTo($this->requesterA, TicketWorkHoursNotification::class, function ($n) use ($ticketA) {
            return (int) $n->ticket->id === (int) $ticketA->id
                && str_starts_with($n->toArray($this->requesterA)['title'], 'Tiket dijeda sementara:');
        });

        // Saat B selesai, A otomatis kembali ke status asalnya (on_proses) sebelum C.
        Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00'));
        TicketStatusService::applyTransition($ticketB, 'on_proses', 'Kerjakan urgen', $this->operator);
        TicketStatusService::applyTransition($ticketB->fresh(), 'solve', 'Selesai urgen', $this->operator);

        $this->assertSame('on_proses', $ticketA->fresh()->status);
        $this->assertNull($ticketA->fresh()->assignment_queued_at);
        $this->assertSame('pending', $ticketC->fresh()->status);
    }

    public function test_operator_self_swap_succeeds_without_self_notification(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00'));
        $ticketA = $this->createTicket($this->requesterA, 'Sedang', 'on_proses', $this->operator->id);
        $ticketB = $this->createTicket($this->requesterB, 'Urgen');
        TicketWorkQueue::assign($ticketB, $this->operator, $this->coordinator);
        Notification::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-20 09:15:00'));
        $this->actingAs($this->operator, 'admin')
            ->post(route('admin.tiket.activate-from-queue', $ticketB->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('open', $ticketB->fresh()->status);
        $this->assertSame('pending', $ticketA->fresh()->status);

        Notification::assertNotSentTo($this->operator, TicketWorkHoursNotification::class);
        Notification::assertSentTo($this->requesterB, TicketWorkHoursNotification::class);
        Notification::assertSentTo($this->requesterA, TicketWorkHoursNotification::class);
    }

    public function test_swap_is_rejected_outside_working_hours_without_mutating_tickets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00'));
        $ticketA = $this->createTicket($this->requesterA, 'Sedang', 'on_proses', $this->operator->id);
        $ticketB = $this->createTicket($this->requesterB, 'Urgen');
        TicketWorkQueue::assign($ticketB, $this->operator, $this->coordinator);

        // Di luar jam kerja (17:00).
        Carbon::setTestNow(Carbon::parse('2026-07-20 17:00:00'));
        $this->actingAs($this->operator, 'admin')
            ->post(route('admin.tiket.activate-from-queue', $ticketB->id))
            ->assertRedirect()
            ->assertSessionHas('error', 'Pertukaran tiket antrean hanya dapat dilakukan pada jam kerja.');

        Sanctum::actingAs($this->operator, ['*']);
        $this->postJson("/api/tickets/{$ticketB->id}/activate-from-queue")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pertukaran tiket antrean hanya dapat dilakukan pada jam kerja.');

        $this->assertSame('on_proses', $ticketA->fresh()->status);
        $this->assertSame('pending', $ticketB->fresh()->status);
        $this->assertNotNull($ticketB->fresh()->assignment_queued_at);
    }

    public function test_unauthorized_admin_cannot_swap_another_operators_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00'));
        $this->createTicket($this->requesterA, 'Sedang', 'on_proses', $this->operator->id);
        $ticketB = $this->createTicket($this->requesterB, 'Urgen');
        TicketWorkQueue::assign($ticketB, $this->operator, $this->coordinator);

        $this->actingAs($this->otherOperator, 'admin')
            ->post(route('admin.tiket.activate-from-queue', $ticketB->id))
            ->assertStatus(403);

        Sanctum::actingAs($this->otherOperator, ['*']);
        $this->postJson("/api/tickets/{$ticketB->id}/activate-from-queue")
            ->assertStatus(403);

        Sanctum::actingAs($this->requesterB, ['*']);
        $this->postJson("/api/tickets/{$ticketB->id}/activate-from-queue")
            ->assertStatus(403);

        $this->assertSame('pending', $ticketB->fresh()->status);
        $this->assertNotNull($ticketB->fresh()->assignment_queued_at);
    }

    public function test_swap_rejects_active_or_manual_pending_tickets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00'));
        $active = $this->createTicket($this->requesterA, 'Sedang', 'on_proses', $this->operator->id);
        $manualPending = $this->createTicket($this->requesterB, 'Sedang', 'pending', $this->operator->id);

        $this->actingAs($this->operator, 'admin')
            ->post(route('admin.tiket.activate-from-queue', $active->id))
            ->assertRedirect()
            ->assertSessionHas('error', 'Hanya tiket yang sedang berada di antrean yang dapat ditukar ke aktif.');

        $this->actingAs($this->operator, 'admin')
            ->post(route('admin.tiket.activate-from-queue', $manualPending->id))
            ->assertRedirect()
            ->assertSessionHas('error', 'Hanya tiket yang sedang berada di antrean yang dapat ditukar ke aktif.');

        $this->assertSame('on_proses', $active->fresh()->status);
        $this->assertSame('pending', $manualPending->fresh()->status);
    }

    public function test_api_activate_from_queue_succeeds_and_returns_updated_ticket(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00'));
        $ticketA = $this->createTicket($this->requesterA, 'Sedang', 'on_proses', $this->operator->id);
        $ticketB = $this->createTicket($this->requesterB, 'Urgen');
        TicketWorkQueue::assign($ticketB, $this->operator, $this->coordinator);

        Sanctum::actingAs($this->coordinator, ['*']);
        $this->postJson("/api/tickets/{$ticketB->id}/activate-from-queue")
            ->assertOk()
            ->assertJsonPath('message', 'Tiket antrean berhasil dijadikan tiket aktif.')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.id', $ticketB->formatted_id);

        $this->assertSame('pending', $ticketA->fresh()->status);
        $this->assertSame('open', $ticketB->fresh()->status);
    }
}
