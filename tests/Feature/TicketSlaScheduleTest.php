<?php

namespace Tests\Feature;

use App\Exports\LaporanTiketExport;
use App\Models\Admin;
use App\Models\FormField;
use App\Models\SlaConfig;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use App\Models\User;
use App\Services\TicketSlaSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketSlaScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Admin $admin;
    private Unit $unit;
    private SubUnit $subUnit;
    private FormField $startField;
    private FormField $endField;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Jakarta']);
        Notification::fake();

        $this->user = User::factory()->create();
        $this->unit = Unit::create(['nama_unit' => 'Sarpras', 'deskripsi' => 'Sarpras', 'aktif' => true]);

        $this->subUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Sewa Ruang Rapat',
            'deskripsi' => 'Ruang rapat',
            'aktif' => true,
            'is_monitored' => false,
        ]);

        $this->startField = $this->makeField(101, $this->subUnit->id, 'Tanggal Awal', 'tanggal', 1);
        $this->endField = $this->makeField(102, $this->subUnit->id, 'Tanggal Akhir', 'tanggal', 2);

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'admin']);
        $this->admin = Admin::create([
            'username' => 'sla_superadmin',
            'email' => 'sla-super@example.com',
            'name' => 'Super Admin SLA',
            'password' => Hash::make('password'),
        ]);
        $this->admin->assignRole('Super Admin');
        $this->admin->units()->attach($this->unit->id);

        // SlaConfig::$thresholdCache bersifat statis dan bertahan antar test dalam satu proses.
        $cache = new \ReflectionProperty(SlaConfig::class, 'thresholdCache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);

        foreach ([
            ['Sedang', 'respon', 60],
            ['Tinggi', 'respon', 30],
            ['Sedang', 'penyelesaian', 480],
            ['Tinggi', 'penyelesaian', 240],
        ] as [$priority, $jenis, $minutes]) {
            SlaConfig::create([
                'sub_unit_id' => null,
                'priority' => $priority,
                'jenis' => $jenis,
                'threshold_minutes' => $minutes,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Field IDs must not be a consecutive run starting at 1: `form_data` is stored as JSON,
     * and such a key set encodes as a list, losing the field-id keys.
     */
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

    private int $scheduledSeq = 0;

    /** Sub-unit yang memetakan satu tanggal + jam mulai/selesai (jadwal eksplisit). */
    private function scheduledSubUnit(): array
    {
        $this->scheduledSeq++;
        $base = 200 + ($this->scheduledSeq * 10);

        $scheduled = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Peminjaman Ruang',
            'deskripsi' => 'Ruang',
            'aktif' => true,
            'is_monitored' => true,
            'monitor_kategori' => 'Ruang',
        ]);
        $asset = $this->makeField($base + 1, $scheduled->id, 'Aset', 'teks_pendek', 1);
        $date = $this->makeField($base + 2, $scheduled->id, 'Tanggal Pakai', 'tanggal', 2);
        $from = $this->makeField($base + 3, $scheduled->id, 'Jam Mulai', 'waktu', 3);
        $to = $this->makeField($base + 4, $scheduled->id, 'Jam Selesai', 'waktu', 4);
        $scheduled->update([
            'monitor_asset_field_id' => $asset->id,
            'monitor_date_field_id' => $date->id,
            'monitor_start_field_id' => $from->id,
            'monitor_end_field_id' => $to->id,
        ]);

        return [$scheduled, $asset, $date, $from, $to];
    }

    /** Tiket + tracking untuk jadwal Sabtu 09:00-13:00 (tanggal tetap 2026-07-18). */
    private function scheduledTicket(string $status, string $startTime = '09:00', string $endTime = '13:00'): array
    {
        [$scheduled, $asset, $date, $from, $to] = $this->scheduledSubUnit();

        $ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $scheduled->id,
            'form_data' => [
                (string) $asset->id => 'Ruang A',
                (string) $date->id => '2026-07-18',
                (string) $from->id => $startTime,
                (string) $to->id => $endTime,
            ],
            'status' => $status,
            'priority' => 'Sedang',
        ]);

        $calculator = new \App\Services\SlaCalculator;
        $window = TicketSlaSchedule::window(
            TicketSlaSchedule::inputs($scheduled, $scheduled->formFields()->get(), $ticket->form_data)
        );
        $calculator->createTracking($ticket, $window);

        \App\Models\RoomVehicleBooking::create([
            'ticket_id' => $ticket->id,
            'tipe' => 'Ruang',
            'nama_aset' => 'Ruang A',
            'tanggal_mulai' => $window['start'],
            'tanggal_selesai' => $window['end'],
            'status' => 'open',
        ]);

        return [$ticket, $calculator, $window];
    }

    private function tracking(Ticket $ticket, int $pausedMinutes = 0): void
    {
        $ticket->slaTracking()->create([
            'sla_response_deadline' => $ticket->created_at->copy()->addHour(),
            'sla_resolution_deadline' => $ticket->created_at->copy()->addHours(8),
            'total_paused_minutes' => $pausedMinutes,
        ]);
    }

    private function formData(array $extra = []): array
    {
        // `array_merge` renumbers numeric-string keys; `+` keeps the field-id keys.
        return [
            (string) $this->startField->id => '2026-07-18',
            (string) $this->endField->id => '2026-07-19',
        ] + $extra;
    }

    private function ticketFor(User $user, array $formData, string $status = 'open'): Ticket
    {
        return Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => $formData,
            'status' => $status,
        ]);
    }

    public function test_all_three_entrypoints_derive_resolution_window_from_the_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00')); // Jumat, dalam jam kerja

        $this->actingAs($this->user, 'web')
            ->post(route('tiket.store'), [
                'unit_id' => $this->unit->id,
                'sub_unit_id' => $this->subUnit->id,
                'form_data' => $this->formData(),
            ])->assertRedirect();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.tiket.store'), [
                'unit_id' => $this->unit->id,
                'sub_unit_id' => $this->subUnit->id,
                'form_data' => $this->formData(),
            ])->assertRedirect();

        Sanctum::actingAs($this->user, ['*']);
        $this->postJson('/api/tickets', [
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => $this->formData(),
        ])->assertStatus(201);

        $this->assertSame(3, Ticket::count());

        foreach (Ticket::with('slaTracking')->get() as $ticket) {
            $sla = $ticket->slaTracking;
            $this->assertNotNull($sla, "Tiket {$ticket->id} tidak punya tracking SLA.");
            $this->assertSame('2026-07-18 00:00:00', $sla->sla_resolution_starts_at->format('Y-m-d H:i:s'));
            $this->assertSame('2026-07-19 23:59:59', $sla->sla_resolution_base_deadline->format('Y-m-d H:i:s'));
            $this->assertSame('2026-07-19 23:59:59', $sla->sla_resolution_deadline->format('Y-m-d H:i:s'));
            // SLA respons tetap dari konfigurasi prioritas, bukan jadwal.
            $this->assertSame('2026-07-17 11:00:00', $sla->sla_response_deadline->format('Y-m-d H:i:s'));
        }
    }

    public function test_single_date_or_report_labels_fall_back_to_working_hour_configuration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));

        $onlyStart = $this->ticketFor($this->user, [(string) $this->startField->id => '2026-07-18']);
        $start = new \App\Services\SlaCalculator;
        $start->createTracking($onlyStart, TicketSlaSchedule::window(
            TicketSlaSchedule::inputs($this->subUnit, $this->subUnit->formFields()->get(), $onlyStart->form_data)
        ));

        $this->assertNull($onlyStart->fresh()->slaTracking->sla_resolution_starts_at);
        // 480 menit kerja dari Jumat 10:00 = sisa 360 menit hari itu + 120 menit Senin.
        $this->assertSame('2026-07-20 10:00:00', $onlyStart->fresh()->slaTracking->sla_resolution_deadline->format('Y-m-d H:i:s'));

        $from = $this->makeField(103, $this->subUnit->id, 'Data dari Tanggal', 'tanggal', 3);
        $to = $this->makeField(104, $this->subUnit->id, 'Data sampai Tanggal', 'tanggal', 4);

        $reportLabels = $this->ticketFor($this->user, [
            (string) $from->id => '2026-07-18',
            (string) $to->id => '2026-07-19',
        ]);

        $this->assertNull(TicketSlaSchedule::window(TicketSlaSchedule::inputs(
            $this->subUnit,
            $this->subUnit->formFields()->get(),
            $reportLabels->form_data
        )));
        $this->assertNull($reportLabels->form_data === [] ? null : (new \App\Services\SlaCalculator)
            ->createTracking($reportLabels, null)->sla_resolution_starts_at);
    }

    public function test_api_rejects_an_impossible_calendar_date_and_creates_no_ticket(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));
        Sanctum::actingAs($this->user, ['*']);

        $response = $this->postJson('/api/tickets', [
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => [
                (string) $this->startField->id => '2026-02-30',
                (string) $this->endField->id => '2026-03-02',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['form_data.'.$this->startField->id]);
        $this->assertSame(0, Ticket::count());
    }

    public function test_paused_scheduled_resolution_freezes_flags_and_extends_by_the_pause(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00')); // Jumat, dalam jam kerja

        [$ticket, $calculator, $window] = $this->scheduledTicket('open');
        $this->assertSame('2026-07-18 09:00:00', $window['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-18 13:00:00', $window['end']->format('Y-m-d H:i:s'));

        // Sebelum jadwal mulai: bukan warning, bukan pelanggaran.
        Carbon::setTestNow(Carbon::parse('2026-07-18 08:59:00'));
        $this->artisan('sla:check')->assertExitCode(0);
        $sla = $ticket->fresh()->slaTracking;
        $this->assertFalse($sla->is_resolution_half_warned);
        $this->assertFalse($sla->is_resolution_breached);

        // Tertunda di tengah jadwal: tidak ada warning/breach baru, deadline dibekukan.
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00'));
        $calculator->pauseSla($sla, Carbon::now());
        $frozen = $ticket->fresh()->slaTracking->sla_resolution_deadline->format('Y-m-d H:i:s');

        Carbon::setTestNow(Carbon::parse('2026-07-18 15:00:00'));
        $this->artisan('sla:check')->assertExitCode(0);
        $sla = $ticket->fresh()->slaTracking;
        $this->assertFalse($sla->is_resolution_half_warned);
        $this->assertFalse($sla->is_resolution_breached);
        $this->assertSame($frozen, $sla->sla_resolution_deadline->format('Y-m-d H:i:s'));

        $exported = (new LaporanTiketExport([], $this->admin))->map(
            $ticket->fresh()->load('slaTracking', 'subUnit.unit', 'user')
        );
        $this->assertSame('Dijeda', $exported[11]);

        // Lanjut: tenggat bertambah 5 jam (10:00 -> 15:00), booking tidak bergeser.
        $calculator->resumeSla($sla, Carbon::now());
        $sla = $ticket->fresh()->slaTracking;
        $this->assertNull($sla->paused_at);
        $this->assertSame('2026-07-18 18:00:00', $sla->sla_resolution_deadline->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-18 13:00:00', $ticket->fresh()->booking->tanggal_selesai->format('Y-m-d H:i:s'));

        // 50% dari 4 jam = 2 jam sebelum 18:00 -> 16:00.
        Carbon::setTestNow(Carbon::parse('2026-07-18 15:59:00'));
        $this->artisan('sla:check')->assertExitCode(0);
        $this->assertFalse($ticket->fresh()->slaTracking->is_resolution_half_warned);

        Carbon::setTestNow(Carbon::parse('2026-07-18 16:00:00'));
        $this->artisan('sla:check')->assertExitCode(0);
        $this->assertTrue($ticket->fresh()->slaTracking->is_resolution_half_warned);

        Carbon::setTestNow(Carbon::parse('2026-07-18 16:30:00'));
        $this->artisan('sla:check')->assertExitCode(0);
        $this->assertTrue($ticket->fresh()->slaTracking->is_resolution_half_warned);
        $this->assertFalse($ticket->fresh()->slaTracking->is_resolution_breached);

        // Tepat di tenggat belum terlambat; satu detik setelahnya terlambat.
        Carbon::setTestNow(Carbon::parse('2026-07-18 18:00:00'));
        $this->artisan('sla:check')->assertExitCode(0);
        $this->assertFalse($ticket->fresh()->slaTracking->is_resolution_breached);

        Carbon::setTestNow(Carbon::parse('2026-07-18 18:00:01'));
        $this->artisan('sla:check')->assertExitCode(0);
        $this->assertTrue($ticket->fresh()->slaTracking->is_resolution_breached);
    }

    public function test_a_breach_recorded_before_the_pause_survives_resume(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));

        $ticket = $this->ticketFor($this->user, $this->formData());
        $calculator = new \App\Services\SlaCalculator;
        $calculator->createTracking($ticket, null);

        $sla = $ticket->fresh()->slaTracking;
        $sla->update(['is_response_breached' => true, 'paused_at' => Carbon::parse('2026-07-17 10:30:00')]);
        $calculator->resumeSla($sla, Carbon::parse('2026-07-17 11:30:00'));

        $sla = $ticket->fresh()->slaTracking;
        $this->assertTrue($sla->is_response_breached);
        $this->assertNull($sla->paused_at);
        $this->assertSame(60, $sla->total_paused_minutes);
    }

    public function test_pause_before_the_scheduled_start_does_not_extend_the_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 07:00:00')); // Sabtu pagi, sebelum jadwal 09:00

        [$ticket, $calculator] = $this->scheduledTicket('pending');

        // Resume 08:00, masih sebelum jadwal mulai: tidak menambah apa pun.
        $calculator->resumeSla($ticket->fresh()->slaTracking, Carbon::parse('2026-07-18 08:00:00'));
        $sla = $ticket->fresh()->slaTracking;
        $this->assertSame('2026-07-18 13:00:00', $sla->sla_resolution_deadline->format('Y-m-d H:i:s'));
        $this->assertSame(0, $sla->total_paused_minutes);

        // Resume 10:00, di tengah jadwal: memperpanjang tepat 1 jam sampai 14:00.
        [$second, $calculator2] = $this->scheduledTicket('pending');
        $this->assertNull($second->fresh()->slaTracking->sla_response_deadline);

        $calculator2->resumeSla($second->fresh()->slaTracking, Carbon::parse('2026-07-18 10:00:00'));

        $this->assertSame('2026-07-18 14:00:00', $second->fresh()->slaTracking->sla_resolution_deadline->format('Y-m-d H:i:s'));

        // SLA respons tetap memakai jam kerja (Sabtu libur): 60 menit kerja -> Senin 09:00.
        $this->assertSame('2026-07-20 09:00:00', $second->fresh()->slaTracking->sla_response_deadline->format('Y-m-d H:i:s'));
    }

    public function test_ordinary_sla_without_schedule_restarts_from_the_resume_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00')); // Senin
        SlaConfig::create(['sub_unit_id' => $this->subUnit->id, 'priority' => 'Sedang', 'jenis' => 'penyelesaian', 'threshold_minutes' => 60]);

        $ticket = $this->ticketFor($this->user, [], 'pending');
        $ticket->update(['priority' => 'Sedang']);
        $calculator = new \App\Services\SlaCalculator;
        $calculator->createTracking($ticket, null);

        $this->assertNull($ticket->fresh()->slaTracking->sla_resolution_deadline);

        $calculator->resumeSla($ticket->fresh()->slaTracking, Carbon::parse('2026-07-20 10:00:00')); // Senin berikutnya
        $sla = $ticket->fresh()->slaTracking;
        $this->assertSame('2026-07-20 11:00:00', $sla->sla_resolution_deadline->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00'));
        $this->artisan('sla:check')->assertExitCode(0);
        $this->assertFalse($ticket->fresh()->slaTracking->is_resolution_breached);
    }

    public function test_priority_change_keeps_the_extension_and_schedule_edit_moves_the_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));

        $ticket = $this->ticketFor($this->user, $this->formData());
        $ticket->update(['priority' => 'Sedang']);
        $calculator = new \App\Services\SlaCalculator;
        $calculator->createTracking($ticket, TicketSlaSchedule::window(
            TicketSlaSchedule::inputs($this->subUnit, $this->subUnit->formFields()->get(), $ticket->form_data)
        ));

        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson("/api/tickets/{$ticket->id}/priority", ['priority' => 'Tinggi'])
            ->assertStatus(200);
        $this->assertSame('2026-07-19 23:59:59', $ticket->fresh()->slaTracking->sla_resolution_deadline->format('Y-m-d H:i:s'));

        $this->patchJson("/api/tickets/{$ticket->id}/form", [
            'form_data' => [
                (string) $this->startField->id => '2026-07-18',
                (string) $this->endField->id => '2026-07-20',
            ],
            'edit_reason' => 'Perubahan jadwal',
        ])->assertStatus(200);

        $this->assertSame('2026-07-20 23:59:59', $ticket->fresh()->slaTracking->sla_resolution_deadline->format('Y-m-d H:i:s'));
    }

    public function test_migration_backfills_only_active_tickets_with_a_complete_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));

        $scheduled = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Ruang Rapat',
            'deskripsi' => 'Ruang',
            'aktif' => true,
            'is_monitored' => false,
        ]);
        $from = $this->makeField(301, $scheduled->id, 'Tanggal Mulai', 'tanggal', 1);
        $to = $this->makeField(302, $scheduled->id, 'Tanggal Selesai', 'tanggal', 2);

        $migration = require database_path('migrations/2026_11_19_000003_add_sla_resolution_window_to_ticket_sla_tracking_table.php');
        $migration->down();

        $make = function (string $status, array $formData, int $pausedMinutes, bool $resolved) use ($scheduled) {
            $ticket = Ticket::create([
                'user_id' => $this->user->id,
                'unit_id' => $this->unit->id,
                'sub_unit_id' => $scheduled->id,
                'form_data' => $formData,
                'status' => $status,
                'priority' => 'Sedang',
            ]);
            TicketSlaTracking::create([
                'ticket_id' => $ticket->id,
                'sla_response_deadline' => Carbon::parse('2026-07-17 11:00:00'),
                'sla_resolution_deadline' => Carbon::parse('2026-07-17 18:00:00'),
                'resolved_at' => $resolved ? Carbon::parse('2026-07-17 12:00:00') : null,
                'total_paused_minutes' => $pausedMinutes,
                'is_resolution_breached' => $resolved,
            ]);

            return $ticket;
        };

        $active = $make('open', [
            (string) $from->id => '2026-07-18',
            (string) $to->id => '2026-07-19',
        ], 30, false);

        $finished = $make('solve', [
            (string) $from->id => '2026-07-18',
            (string) $to->id => '2026-07-19',
        ], 30, true);

        $incomplete = $make('on_proses', [(string) $from->id => '2026-07-18'], 0, false);

        $migration->up();

        $activeSla = $active->fresh()->slaTracking;
        $this->assertSame('2026-07-18 00:00:00', $activeSla->sla_resolution_starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-19 23:59:59', $activeSla->sla_resolution_base_deadline->format('Y-m-d H:i:s'));
        // Kredit jeda lama (30 menit) dipertahankan.
        $this->assertSame('2026-07-20 00:29:59', $activeSla->sla_resolution_deadline->format('Y-m-d H:i:s'));
        $this->assertFalse((bool) $activeSla->is_resolution_breached);

        $finishedSla = $finished->fresh()->slaTracking;
        $this->assertNull($finishedSla->sla_resolution_starts_at);
        $this->assertSame('2026-07-17 18:00:00', $finishedSla->sla_resolution_deadline->format('Y-m-d H:i:s'));
        $this->assertTrue((bool) $finishedSla->is_resolution_breached);

        $incompleteSla = $incomplete->fresh()->slaTracking;
        $this->assertNull($incompleteSla->sla_resolution_starts_at);
        $this->assertSame('2026-07-17 18:00:00', $incompleteSla->sla_resolution_deadline->format('Y-m-d H:i:s'));
    }
}
