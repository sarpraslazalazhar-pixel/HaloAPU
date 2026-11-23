<?php

namespace Tests\Feature;

use App\Events\ActiveAssignedTicketChanged;
use App\Models\Admin;
use App\Models\FormField;
use App\Models\SlaConfig;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\TicketWorkQueue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketWorkQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Admin $superAdmin;
    private Admin $operatorA;
    private Admin $operatorB;
    private Unit $unit;
    private SubUnit $subUnit;
    private FormField $startField;
    private FormField $endField;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Jakarta']);
        Notification::fake();

        $cache = new \ReflectionProperty(SlaConfig::class, 'thresholdCache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);

        $this->user = User::factory()->create();
        $this->unit = Unit::create(['nama_unit' => 'IT Support', 'deskripsi' => 'IT', 'aktif' => true]);
        $this->subUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Perbaikan Jaringan',
            'deskripsi' => 'Jaringan',
            'aktif' => true,
            'is_monitored' => false,
        ]);

        $this->startField = $this->makeField(101, $this->subUnit->id, 'Tanggal Awal', 'tanggal', 1);
        $this->endField = $this->makeField(102, $this->subUnit->id, 'Tanggal Akhir', 'tanggal', 2);

        foreach ([
            ['Sedang', 'respon', 60],
            ['Sedang', 'penyelesaian', 480],
        ] as [$priority, $jenis, $minutes]) {
            SlaConfig::create([
                'sub_unit_id' => null,
                'priority' => $priority,
                'jenis' => $jenis,
                'threshold_minutes' => $minutes,
            ]);
        }

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'admin']);

        $this->superAdmin = $this->makeAdmin('queue_superadmin', 'Super Admin');
        $this->operatorA = $this->makeAdmin('queue_operator_a', 'Operator');
        $this->operatorB = $this->makeAdmin('queue_operator_b', 'Operator');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeField(int $id, int $subUnitId, string $label, string $type, int $urutan): FormField
    {
        $field = new FormField();
        $field->forceFill([
            'id' => $id,
            'sub_unit_id' => $subUnitId,
            'label' => $label,
            'tipe_field' => $type,
            'wajib' => false,
            'urutan' => $urutan,
        ])->save();

        return $field;
    }

    private function makeAdmin(string $username, string $role): Admin
    {
        $admin = Admin::create([
            'username' => $username,
            'email' => "{$username}@example.com",
            'name' => strtoupper($username),
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole($role);
        $admin->units()->attach($this->unit->id);

        return $admin;
    }

    private function ticket(string $status = 'open', ?string $endDate = null): Ticket
    {
        $formData = [(string) $this->startField->id => '2026-07-13'];
        if ($endDate !== null) {
            $formData[(string) $this->endField->id] = $endDate;
        }

        return Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => $formData,
            'status' => $status,
            'priority' => 'Sedang',
        ]);
    }

    private function tracking(Ticket $ticket, array $attributes = []): void
    {
        (new \App\Services\SlaCalculator)->createTracking($ticket, null);
        if ($attributes !== []) {
            $ticket->fresh()->slaTracking->update($attributes);
        }
    }

    public function test_assignment_keeps_one_active_slot_and_queues_the_rest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00')); // Senin, jam kerja

        $a = $this->ticket('open');
        $this->tracking($a);

        $this->actingAs($this->superAdmin, 'admin')
            ->patch(route('admin.tiket.assign', $a), ['assigned_admin_id' => $this->operatorA->id])
            ->assertRedirect();

        $a->refresh();
        $this->assertSame('open', $a->status);
        $this->assertSame($this->operatorA->id, (int) $a->assigned_admin_id);
        $this->assertNull($a->slaTracking->responded_at);

        $b = $this->ticket('open');
        $this->tracking($b);
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:15:00'));
        $this->actingAs($this->superAdmin, 'admin')
            ->patch(route('admin.tiket.assign', $b), ['assigned_admin_id' => $this->operatorA->id]);

        $b->refresh();
        $this->assertSame('pending', $b->status);
        $this->assertSame($this->operatorA->id, (int) $b->assigned_admin_id);
        $this->assertSame('open', $b->assignment_resume_status);
        $this->assertNotNull($b->assignment_queued_at);
        $this->assertNotNull($b->slaTracking->paused_at);

        $c = $this->ticket('open');
        $this->tracking($c);
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:20:00'));
        Sanctum::actingAs($this->superAdmin, ['*']);
        $this->postJson("/api/tickets/{$c->id}/assign", ['admin_id' => $this->operatorA->id])
            ->assertStatus(200)
            ->assertJsonPath('message', 'Tiket masuk antrean petugas.');

        $c->refresh();
        $this->assertSame('pending', $c->status);
        $this->assertSame($this->operatorA->id, (int) $c->assigned_admin_id);

        $this->assertSame(1, Ticket::where('assigned_admin_id', $this->operatorA->id)
            ->whereIn('status', TicketWorkQueue::ACTIVE_STATUSES)->count());

        // Diproses pada tiket antrean ditolak tanpa menggeser SLA.
        $deadlineBefore = $b->fresh()->slaTracking->sla_resolution_deadline->format('Y-m-d H:i:s');
        $this->actingAs($this->superAdmin, 'admin');
        $response = $this->postJson("/api/tickets/{$b->id}/status", ['status' => 'on_proses']);
        $response->assertStatus(422);
        $this->assertSame($deadlineBefore, $b->fresh()->slaTracking->sla_resolution_deadline->format('Y-m-d H:i:s'));

        // Belum pelanggaran saat dijeda.
        Carbon::setTestNow(Carbon::parse('2026-07-13 10:05:00'));
        $this->artisan('sla:check')->assertExitCode(0);
        $this->assertFalse((bool) $b->fresh()->slaTracking->is_resolution_breached);

        // Selesaikan A -> B mendapat giliran, C tetap antre.
        Carbon::setTestNow(Carbon::parse('2026-07-13 10:15:00'));
        $this->actingAs($this->superAdmin, 'admin');
        $this->postJson("/api/tickets/{$a->id}/status", ['status' => 'on_proses'])->assertStatus(200);
        $this->postJson("/api/tickets/{$a->id}/status", ['status' => 'solve'])->assertStatus(200);

        $b->refresh();
        $this->assertSame('open', $b->status);
        $this->assertNull($b->assignment_queued_at);
        $this->assertNull($b->assignment_resume_status);
        $this->assertNull($b->slaTracking->paused_at);
        $this->assertNull($b->slaTracking->responded_at);

        $this->assertSame('pending', $c->fresh()->status);
        $this->assertSame(1, Ticket::where('assigned_admin_id', $this->operatorA->id)
            ->whereIn('status', TicketWorkQueue::ACTIVE_STATUSES)->count());
    }

    public function test_reassigning_to_the_same_operator_does_not_move_the_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));

        $a = $this->ticket('open');
        $this->tracking($a);
        $b = $this->ticket('open');
        $this->tracking($b);

        TicketWorkQueue::assign($a, $this->operatorA, $this->superAdmin);
        TicketWorkQueue::assign($b, $this->operatorA, $this->superAdmin);

        $queuedAt = $b->fresh()->assignment_queued_at->format('Y-m-d H:i:s');
        $logCount = $b->fresh()->logs()->count();

        Carbon::setTestNow(Carbon::parse('2026-07-13 09:45:00'));
        TicketWorkQueue::assign($b->fresh(), $this->operatorA, $this->superAdmin);

        $this->assertSame($queuedAt, $b->fresh()->assignment_queued_at->format('Y-m-d H:i:s'));
        $this->assertSame($logCount, $b->fresh()->logs()->count());
    }

    public function test_manual_pending_and_out_of_hours_tickets_are_not_promoted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));

        $active = $this->ticket('open');
        $this->tracking($active);
        TicketWorkQueue::assign($active, $this->operatorA, $this->superAdmin);

        $manualPending = $this->ticket('pending');
        $this->tracking($manualPending, ['paused_at' => Carbon::now()]);
        TicketWorkQueue::assign($manualPending, $this->operatorA, $this->superAdmin);

        $this->assertSame('pending', $manualPending->fresh()->status);
        $this->assertNull($manualPending->fresh()->assignment_queued_at);
        $this->assertNull($manualPending->fresh()->assignment_resume_status);

        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00'));
        TicketWorkQueue::assign($active->fresh(), null, $this->superAdmin);

        $this->assertSame('pending', $manualPending->fresh()->status);
        $this->assertNull($manualPending->fresh()->assignment_queued_at);
    }

    public function test_releasing_the_assignee_promotes_the_oldest_queued_ticket(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));

        $active = $this->ticket('open');
        $this->tracking($active);
        $queued = $this->ticket('open');
        $this->tracking($queued);

        TicketWorkQueue::assign($active, $this->operatorA, $this->superAdmin);
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:10:00'));
        TicketWorkQueue::assign($queued, $this->operatorA, $this->superAdmin);
        $this->assertSame('pending', $queued->fresh()->status);

        Carbon::setTestNow(Carbon::parse('2026-07-13 09:30:00'));
        TicketWorkQueue::assign($active->fresh(), null, $this->superAdmin);

        $this->assertSame('open', $queued->fresh()->status);
        $this->assertNull($queued->fresh()->assignment_queued_at);
        $this->assertNull($queued->fresh()->slaTracking->paused_at);
        $this->assertSame($this->operatorA->id, (int) $queued->fresh()->assigned_admin_id);
    }

    public function test_cancel_hands_the_slot_to_the_queued_ticket(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));

        $active = $this->ticket('open');
        $this->tracking($active);
        $queued = $this->ticket('open');
        $this->tracking($queued);

        TicketWorkQueue::assign($active, $this->operatorA, $this->superAdmin);
        TicketWorkQueue::assign($queued, $this->operatorA, $this->superAdmin);

        $this->actingAs($this->superAdmin, 'admin')
            ->patch(route('admin.tiket.cancel', $active))
            ->assertRedirect();

        $this->assertContains($active->fresh()->status, ['reject', 'dibatalkan']);
        $this->assertSame('open', $queued->fresh()->status);
        $this->assertNull($queued->fresh()->assignment_queued_at);
    }

    public function test_revision_request_waits_then_restores_need_revision_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));

        $this->subUnit->update(['is_revision_enabled' => true]);
        $solved = $this->ticket('solve');
        $this->tracking($solved, ['resolved_at' => Carbon::now()]);
        // `solve` bukan status aktif, jadi penugasan ini tidak memakai slot.
        TicketWorkQueue::assign($solved, $this->operatorA, $this->superAdmin);

        $active = $this->ticket('open');
        $this->tracking($active);
        TicketWorkQueue::assign($active, $this->operatorA, $this->superAdmin);
        $resolvedAt = $solved->fresh()->slaTracking->resolved_at->format('Y-m-d H:i:s');
        $revisionCount = (int) $solved->revision_count;

        Carbon::setTestNow(Carbon::parse('2026-07-13 09:20:00'));
        Sanctum::actingAs($this->user, ['*']);
        $this->postJson("/api/tickets/{$solved->id}/revision", ['catatan' => 'Perlu perbaikan'])->assertStatus(200);

        $this->assertSame('pending', $solved->fresh()->status);
        $this->assertSame('need_revision', $solved->fresh()->assignment_resume_status);
        $this->assertSame($revisionCount + 1, (int) $solved->fresh()->revision_count);

        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00'));
        Sanctum::actingAs($this->superAdmin, ['*']);
        $this->postJson("/api/tickets/{$active->id}/status", ['status' => 'on_proses'])->assertStatus(200);
        $this->postJson("/api/tickets/{$active->id}/status", ['status' => 'solve'])->assertStatus(200);

        $this->assertSame('need_revision', $solved->fresh()->status);
        $this->assertSame($revisionCount + 1, (int) $solved->fresh()->revision_count);
        $this->assertSame($resolvedAt, $solved->fresh()->slaTracking->resolved_at->format('Y-m-d H:i:s'));
    }

    public function test_active_ticket_endpoint_and_shared_prop_follow_the_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));

        $a = $this->ticket('open');
        $this->tracking($a);

        $this->actingAs($this->operatorA, 'admin')
            ->getJson(route('admin.tiket.aktif'))
            ->assertStatus(200)
            ->assertJsonPath('data.active', null)
            ->assertJsonPath('data.queue', []);

        TicketWorkQueue::assign($a, $this->operatorA, $this->superAdmin);

        $this->actingAs($this->operatorA, 'admin')
            ->getJson(route('admin.tiket.aktif'))
            ->assertStatus(200)
            ->assertJsonPath('data.active.id', (int) $a->id)
            ->assertJsonPath('data.active.status', 'open')
            ->assertJsonPath('data.active.formatted_id', $a->fresh()->formatted_id)
            ->assertJsonPath('data.queue', []);

        // Petugas lain tidak melihat tiket ini.
        $this->actingAs($this->operatorB, 'admin')
            ->getJson(route('admin.tiket.aktif'))
            ->assertStatus(200)
            ->assertJsonPath('data.active', null);

        $this->app['auth']->guard('admin')->logout();
        $this->get(route('admin.tiket.aktif'))->assertRedirect(route('login'));
    }

    public function test_queue_positions_follow_urgency_then_arrival(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));

        $active = $this->ticket('open');
        $this->tracking($active);
        TicketWorkQueue::assign($active, $this->operatorA, $this->superAdmin);

        $first = $this->ticket('open');
        $this->tracking($first);
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:10:00'));
        TicketWorkQueue::assign($first, $this->operatorA, $this->superAdmin);

        $second = $this->ticket('open');
        $this->tracking($second);
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:20:00'));
        TicketWorkQueue::assign($second, $this->operatorA, $this->superAdmin);

        $payload = TicketWorkQueue::payload((int) $this->operatorA->id);
        $this->assertSame((int) $active->id, $payload['active']['id']);
        $this->assertSame([1, 2], array_column($payload['queue'], 'position'));
        $this->assertSame([(int) $first->id, (int) $second->id], array_column($payload['queue'], 'id'));
        $this->assertFalse($payload['queue'][0]['urgent']);

        // Urgen melompat ke depan antrean.
        $this->actingAs($this->superAdmin, 'admin')
            ->patch(route('admin.tiket.priority', $second), ['priority' => 'Urgen'])
            ->assertRedirect();

        $payload = TicketWorkQueue::payload((int) $this->operatorA->id);
        $this->assertSame([(int) $second->id, (int) $first->id], array_column($payload['queue'], 'id'));
        $this->assertTrue($payload['queue'][0]['urgent']);

        // Promosi mengambil tiket urgen lebih dulu.
        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00'));
        TicketWorkQueue::assign($active->fresh(), null, $this->superAdmin);

        $this->assertSame('open', $second->fresh()->status);
        $this->assertSame('pending', $first->fresh()->status);
    }

    public function test_slot_change_broadcasts_only_to_the_affected_operator(): void
    {
        Event::fake([ActiveAssignedTicketChanged::class]);
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));

        $a = $this->ticket('open');
        $this->tracking($a);

        // Hanya masuk antrean tidak mengubah pop-up (belum memegang slot).
        TicketWorkQueue::assign($a, $this->operatorA, $this->superAdmin);
        Event::assertDispatched(ActiveAssignedTicketChanged::class, fn ($event) => true);

        Event::fake([ActiveAssignedTicketChanged::class]);
        $b = $this->ticket('open');
        $this->tracking($b);
        TicketWorkQueue::assign($b, $this->operatorA, $this->superAdmin);
        Event::assertNotDispatched(ActiveAssignedTicketChanged::class);
    }

    public function test_queue_migration_keeps_one_active_ticket_per_operator(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));

        $first = $this->ticket('on_proses');
        $this->tracking($first);
        $first->update(['assigned_admin_id' => $this->operatorA->id, 'created_at' => Carbon::parse('2026-07-13 08:00:00')]);

        $second = $this->ticket('open');
        $this->tracking($second);
        $second->update(['assigned_admin_id' => $this->operatorA->id, 'created_at' => Carbon::parse('2026-07-13 08:10:00')]);

        $legacyPending = $this->ticket('pending');
        $this->tracking($legacyPending, ['paused_at' => Carbon::now()]);
        $legacyPending->update(['assigned_admin_id' => $this->operatorA->id]);

        $migration = require database_path('migrations/2026_11_19_000004_add_assignment_queue_to_tickets_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame('on_proses', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame('open', $second->fresh()->assignment_resume_status);
        $this->assertNotNull($second->fresh()->assignment_queued_at);
        $this->assertNotNull($second->fresh()->slaTracking->paused_at);

        $this->assertNull($legacyPending->fresh()->assignment_queued_at);
        $this->assertNull($legacyPending->fresh()->assignment_resume_status);
    }

    public function test_operator_loads_on_admin_index_counts_all_assigned_active_and_pending_tickets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));

        $active = $this->ticket('on_proses');
        $this->tracking($active);
        TicketWorkQueue::assign($active, $this->operatorA, $this->superAdmin);

        $queued = $this->ticket('open');
        $this->tracking($queued);
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:05:00'));
        TicketWorkQueue::assign($queued, $this->operatorA, $this->superAdmin);

        $manualPendingIds = [];
        for ($i = 0; $i < 5; $i++) {
            Carbon::setTestNow(Carbon::parse('2026-07-13 09:10:00')->addMinutes($i));
            $pendingTicket = $this->ticket('pending');
            $this->tracking($pendingTicket, ['paused_at' => Carbon::now()]);
            $pendingTicket->update(['assigned_admin_id' => $this->operatorA->id]);
            $manualPendingIds[] = (int) $pendingTicket->id;
        }

        // Tiket selesai tidak masuk beban kerja aktif/terbuka.
        $solved = $this->ticket('solve');
        $solved->update(['assigned_admin_id' => $this->operatorA->id]);

        $this->actingAs($this->superAdmin, 'admin')
            ->get(route('admin.tiket.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->has('operatorLoads', 1)
                ->where('operatorLoads.0.admin_id', $this->operatorA->id)
                ->where('operatorLoads.0.total', 7)
                ->where('operatorLoads.0.working', 1)
                ->where('operatorLoads.0.queued', 1)
                ->where('operatorLoads.0.paused', 5)
                ->has('operatorLoads.0.tickets', 7)
                ->where('operatorLoads.0.tickets.0.id', (int) $active->id)
                ->where('operatorLoads.0.tickets.0.position', null)
                ->where('operatorLoads.0.tickets.0.is_manual_pending', false)
                ->where('operatorLoads.0.tickets.1.id', (int) $queued->id)
                ->where('operatorLoads.0.tickets.1.position', 1)
                ->where('operatorLoads.0.tickets.2.id', $manualPendingIds[0])
                ->where('operatorLoads.0.tickets.2.position', null)
                ->where('operatorLoads.0.tickets.2.is_manual_pending', true)
                ->where('operatorLoads.0.tickets.2.is_work_hours_paused', false)
            );
    }

    public function test_operator_loads_on_admin_index_respects_accessible_by_unit_scope(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));

        $otherUnit = Unit::create(['nama_unit' => 'Keuangan', 'deskripsi' => 'Finance', 'aktif' => true]);
        $otherSubUnit = SubUnit::create([
            'unit_id' => $otherUnit->id,
            'nama_layanan' => 'Reimbursement',
            'deskripsi' => 'Keuangan',
            'aktif' => true,
            'is_monitored' => false,
        ]);

        $otherOperator = Admin::create([
            'username' => 'queue_operator_other_unit',
            'email' => 'queue_operator_other_unit@example.com',
            'name' => 'OPERATOR OTHER',
            'password' => Hash::make('password'),
        ]);
        $otherOperator->assignRole('Operator');
        $otherOperator->units()->attach($otherUnit->id);

        $unitAdmin = $this->makeAdmin('queue_unit_admin', 'Operator');

        $visibleTicket = $this->ticket('open');
        $this->tracking($visibleTicket);
        TicketWorkQueue::assign($visibleTicket, $this->operatorA, $this->superAdmin);

        $hiddenTicket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $otherUnit->id,
            'sub_unit_id' => $otherSubUnit->id,
            'form_data' => [(string) $this->startField->id => '2026-07-13'],
            'status' => 'pending',
            'priority' => 'Sedang',
            'assigned_admin_id' => $otherOperator->id,
        ]);

        $this->actingAs($unitAdmin, 'admin')
            ->get(route('admin.tiket.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->has('operatorLoads', 1)
                ->where('operatorLoads.0.admin_id', $this->operatorA->id)
                ->where('operatorLoads.0.total', 1)
            );

        $this->assertSame('pending', $hiddenTicket->fresh()->status);
    }
}
