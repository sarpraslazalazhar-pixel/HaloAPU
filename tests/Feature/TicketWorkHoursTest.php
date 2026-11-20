<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\FormField;
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

class TicketWorkHoursTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Admin $superAdmin;
    private Admin $operatorA;
    private Admin $operatorB;
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

        $this->user = User::factory()->create();
        $this->unit = Unit::create(['nama_unit' => 'IT Support', 'deskripsi' => 'IT', 'aktif' => true]);
        $this->subUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Perbaikan Jaringan',
            'deskripsi' => 'Jaringan',
            'aktif' => true,
            'is_monitored' => false,
        ]);

        $this->superAdmin = $this->makeAdmin('workhours_super', 'Super Admin');
        $this->operatorA = $this->makeAdmin('workhours_a', 'Operator');
        $this->operatorB = $this->makeAdmin('workhours_b', 'Operator');
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

    private function ticket(string $status = 'open', ?int $adminId = null): Ticket
    {
        return Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['judul' => 'AC bocor'],
            'status' => $status,
            'priority' => 'Sedang',
            'assigned_admin_id' => $adminId,
        ]);
    }

    private function tracking(Ticket $ticket, array $attributes = []): void
    {
        (new SlaCalculator)->createTracking($ticket, null);
        if ($attributes !== []) {
            $ticket->fresh()->slaTracking->update($attributes);
        }
    }

    public function test_work_hours_state_uses_half_open_interval(): void
    {
        $calendar = new SlaCalculator;

        $open = $calendar->workingHoursState(Carbon::parse('2026-07-17 15:59:59'));
        $this->assertTrue($open['is_open']);

        $closed = $calendar->workingHoursState(Carbon::parse('2026-07-17 16:00:00'));
        $this->assertFalse($closed['is_open']);
        $this->assertSame('2026-07-17 16:00:00', $closed['closed_since']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-20 08:00:00', $closed['next_open_at']->format('Y-m-d H:i:s'));

        // Jumat tutup sampai Senin buka tetap satu periode.
        $weekend = $calendar->workingHoursState(Carbon::parse('2026-07-18 10:00:00'));
        $this->assertSame('2026-07-17 16:00:00', $weekend['closed_since']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-20 08:00:00', $weekend['next_open_at']->format('Y-m-d H:i:s'));

        $monday = $calendar->workingHoursState(Carbon::parse('2026-07-20 08:00:00'));
        $this->assertTrue($monday['is_open']);
    }

    public function test_command_pauses_active_tickets_once_per_closed_period(): void
    {
        $operator = $this->operatorA;
        Carbon::setTestNow(Carbon::parse('2026-07-17 15:00:00'));

        $open = $this->ticket('open', $operator->id);
        $this->tracking($open);
        $processing = $this->ticket('on_proses', $operator->id);
        $this->tracking($processing);
        $finished = $this->ticket('solve', $operator->id);

        Carbon::setTestNow(Carbon::parse('2026-07-17 16:00:00'));

        $this->artisan('tickets:work-hours')->assertExitCode(0);

        foreach ([$open, $processing] as $ticket) {
            $fresh = $ticket->fresh();
            $this->assertSame('pending', $fresh->status);
            $this->assertSame($ticket->status, $fresh->work_hours_resume_status);
            $this->assertSame($operator->id, (int) $fresh->assigned_admin_id);
            $this->assertNotNull($fresh->slaTracking->paused_at);
            $this->assertSame(1, $fresh->logs()->where('aksi', 'work_hours_paused')->count());
        }

        $this->assertSame('solve', $finished->fresh()->status);
        $this->assertNull($finished->fresh()->work_hours_resume_status);

        // Pengaju dan petugas masing-masing sekali per periode tutup.
        Notification::assertSentToTimes($this->user, TicketWorkHoursNotification::class, 2);
        Notification::assertSentToTimes($operator, TicketWorkHoursNotification::class, 2);

        // Tick berikutnya, termasuk Sabtu, tidak menulis catatan ulang.
        Carbon::setTestNow(Carbon::parse('2026-07-18 09:00:00'));
        $this->artisan('tickets:work-hours')->assertExitCode(0);
        $this->assertSame(1, $open->fresh()->logs()->where('aksi', 'work_hours_paused')->count());
        $this->assertSame(1, $processing->fresh()->logs()->where('aksi', 'work_hours_paused')->count());
    }

    public function test_queued_tickets_keep_position_and_get_one_note(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 15:00:00'));

        $active = $this->ticket('open', $this->operatorA->id);
        $this->tracking($active);
        $queued = $this->ticket('open');
        $this->tracking($queued);
        TicketWorkQueue::assign($queued, $this->operatorA, $this->superAdmin);

        $queuedAt = $queued->fresh()->assignment_queued_at->format('Y-m-d H:i:s');
        $this->assertSame('open', $queued->fresh()->assignment_resume_status);

        Carbon::setTestNow(Carbon::parse('2026-07-17 16:00:00'));
        $this->artisan('tickets:work-hours')->assertExitCode(0);

        $queued->refresh();
        $this->assertSame('pending', $queued->status);
        $this->assertSame($queuedAt, $queued->assignment_queued_at->format('Y-m-d H:i:s'));
        $this->assertSame('open', $queued->assignment_resume_status);
        $this->assertSame(1, $queued->logs()->where('aksi', 'work_hours_queued')->count());

        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00'));
        $this->artisan('tickets:work-hours')->assertExitCode(0);
        $this->assertSame(1, $queued->fresh()->logs()->where('aksi', 'work_hours_queued')->count());
    }

    public function test_paused_ticket_keeps_slot_and_cannot_resume_before_open(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 15:00:00'));

        $paused = $this->ticket('on_proses', $this->operatorA->id);
        $this->tracking($paused);
        $queued = $this->ticket('open');
        $this->tracking($queued);
        TicketWorkQueue::assign($queued, $this->operatorA, $this->superAdmin);

        Carbon::setTestNow(Carbon::parse('2026-07-17 16:00:00'));
        $this->artisan('tickets:work-hours')->assertExitCode(0);

        $payload = TicketWorkQueue::payload((int) $this->operatorA->id);
        $this->assertSame((int) $paused->id, $payload['active']['id']);
        $this->assertTrue($payload['active']['is_work_hours_paused']);
        $this->assertFalse($payload['work_hours_open']);
        $this->assertTrue(Carbon::parse($payload['next_open_at'])->equalTo(Carbon::parse('2026-07-20 08:00:00')));
        $this->assertSame('pending', $queued->fresh()->status);

        // Belum buka: petugas ditolak.
        $this->actingAs($this->operatorA, 'admin')
            ->post(route('admin.tiket.resume-work', $paused->id))
            ->assertSessionHas('error');

        // Bukan petugas: 403.
        $this->actingAs($this->operatorB, 'admin')
            ->post(route('admin.tiket.resume-work', $paused->id))
            ->assertStatus(403);

        // Senin 08:00: lanjut manual memulihkan status asli.
        Carbon::setTestNow(Carbon::parse('2026-07-20 08:00:00'));
        $payload = TicketWorkQueue::payload((int) $this->operatorA->id);
        $this->assertTrue($payload['work_hours_open']);
        $this->assertNull($payload['next_open_at']);
        $this->assertTrue($payload['active']['is_work_hours_paused']);
        $this->actingAs($this->operatorA, 'admin')
            ->post(route('admin.tiket.resume-work', $paused->id))
            ->assertSessionHas('success');

        $paused->refresh();
        $this->assertSame('on_proses', $paused->status);
        $this->assertNull($paused->work_hours_resume_status);
        $this->assertNull($paused->slaTracking->paused_at);
        $this->assertSame(1, $paused->logs()->where('aksi', 'work_hours_resumed')->count());

        // Scheduler tidak melanjutkan otomatis; antrean tetap menunggu.
        $this->assertSame('pending', $queued->fresh()->status);
    }

    public function test_status_transition_cannot_bypass_work_hours_pause(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 15:00:00'));
        $ticket = $this->ticket('on_proses', $this->operatorA->id);
        $this->tracking($ticket);

        Carbon::setTestNow(Carbon::parse('2026-07-17 16:00:00'));
        $this->artisan('tickets:work-hours')->assertExitCode(0);

        Sanctum::actingAs($this->superAdmin, ['*']);
        $this->postJson("/api/tickets/{$ticket->id}/status", ['status' => 'on_proses'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Gunakan Lanjutkan Tiket setelah jam kerja buka.');

        // Begitu buka, status biasa untuk tiket ber-marker tetap ditolak.
        Carbon::setTestNow(Carbon::parse('2026-07-20 09:00:00'));
        $this->postJson("/api/tickets/{$ticket->id}/status", ['status' => 'solve'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Gunakan Lanjutkan Tiket setelah jam kerja buka.');
    }

    public function test_api_detail_exposes_work_hours_only_for_affected_tickets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 15:00:00'));
        $paused = $this->ticket('on_proses', $this->operatorA->id);
        $this->tracking($paused);
        $normal = $this->ticket('open');
        $this->tracking($normal);

        Carbon::setTestNow(Carbon::parse('2026-07-17 16:00:00'));
        $this->artisan('tickets:work-hours')->assertExitCode(0);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/tickets/{$paused->id}")
            ->assertOk()
            ->assertJsonPath('data.work_hours.reason', 'paused')
            ->assertJsonPath('data.work_hours.resume_status', 'on_proses')
            ->assertJsonPath('data.work_hours.is_open', false)
            ->assertJsonPath('data.work_hours.can_resume', false);

        $this->getJson("/api/tickets/{$normal->id}")
            ->assertOk()
            ->assertJsonPath('data.work_hours', null);

        // Admin luar tidak boleh membaca detail lewat tebakan ID.
        $outsider = $this->makeAdmin('workhours_outsider', 'Operator');
        $outsider->units()->detach();
        Sanctum::actingAs($outsider);
        $this->getJson("/api/tickets/{$paused->id}")->assertStatus(403);
    }

    public function test_api_resume_work_returns_422_before_open_and_200_after(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 15:00:00'));
        $ticket = $this->ticket('need_revision', $this->operatorA->id);
        $this->tracking($ticket, ['responded_at' => Carbon::parse('2026-07-17 14:30:00')]);

        Carbon::setTestNow(Carbon::parse('2026-07-17 16:00:00'));
        $this->artisan('tickets:work-hours')->assertExitCode(0);

        Sanctum::actingAs($this->operatorA, ['*']);
        $this->postJson("/api/tickets/{$ticket->id}/resume-work")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jam kerja belum buka. Tiket belum bisa dilanjutkan.');

        Sanctum::actingAs($this->operatorB, ['*']);
        $this->postJson("/api/tickets/{$ticket->id}/resume-work")->assertStatus(403);

        Carbon::setTestNow(Carbon::parse('2026-07-20 08:30:00'));
        Sanctum::actingAs($this->operatorA, ['*']);
        $this->postJson("/api/tickets/{$ticket->id}/resume-work")
            ->assertOk()
            ->assertJsonPath('data.status', 'need_revision');

        $this->assertSame('need_revision', $ticket->fresh()->status);
    }

    public function test_cancellation_right_survives_a_work_hours_pause(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 15:00:00'));
        $ticket = $this->ticket('open', $this->operatorA->id);
        $this->tracking($ticket);

        Carbon::setTestNow(Carbon::parse('2026-07-17 16:00:00'));
        $this->artisan('tickets:work-hours')->assertExitCode(0);
        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertTrue($ticket->fresh()->isOpenForCancellation());

        $this->actingAs($this->user)
            ->patch(route('tiket.batal', $ticket->id))
            ->assertRedirect();

        $this->assertSame('dibatalkan', $ticket->fresh()->status);
    }

    public function test_scheduler_promotes_one_slot_per_operator_on_open(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 15:00:00'));

        $active = $this->ticket('open', $this->operatorA->id);
        $this->tracking($active);
        $first = $this->ticket('open');
        $this->tracking($first);
        TicketWorkQueue::assign($first, $this->operatorA, $this->superAdmin);
        Carbon::setTestNow(Carbon::parse('2026-07-17 15:01:00'));
        $second = $this->ticket('open');
        $this->tracking($second);
        TicketWorkQueue::assign($second, $this->operatorA, $this->superAdmin);

        Carbon::setTestNow(Carbon::parse('2026-07-17 16:00:00'));
        $this->artisan('tickets:work-hours')->assertExitCode(0);

        // Slot ditahan tiket tertunda; antrean tidak melompat saat tutup maupun saat buka.
        Carbon::setTestNow(Carbon::parse('2026-07-20 08:00:00'));
        $this->artisan('tickets:work-hours')->assertExitCode(0);
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame('pending', $active->fresh()->status);

        // Petugas melanjutkan tiketnya; status asli kembali.
        TicketWorkQueue::resumeAfterHours($active->fresh(), $this->operatorA, new SlaCalculator);
        $this->assertSame('open', $active->fresh()->status);

        // Satu tiket selesai -> antrean pertama naik, kedua tetap menunggu.
        TicketStatusService::applyTransition($active->fresh(), 'on_proses', 'Dikerjakan', $this->operatorA);
        TicketStatusService::applyTransition($active->fresh(), 'solve', 'Selesai', $this->operatorA);

        $this->assertSame('open', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(1, Ticket::where('assigned_admin_id', $this->operatorA->id)
            ->whereIn('status', TicketWorkQueue::ACTIVE_STATUSES)->count());
    }
}
