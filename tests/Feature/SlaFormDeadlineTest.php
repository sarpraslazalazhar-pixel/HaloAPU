<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\FormField;
use App\Models\SlaConfig;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use App\Models\User;
use App\Services\SlaCalculator;
use App\Services\TicketSlaSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SlaFormDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Admin $admin;
    private Unit $unit;
    private SubUnit $design;
    private SubUnit $control;
    private FormField $deadline;
    private FormField $controlDeadline;
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Jakarta']);
        Notification::fake();

        $this->user = User::factory()->create();
        $this->unit = Unit::create(['nama_unit' => 'Humas', 'deskripsi' => 'Humas', 'aktif' => true]);
        $this->design = SubUnit::create(['unit_id' => $this->unit->id, 'nama_layanan' => 'Desain', 'deskripsi' => 'x', 'aktif' => true, 'is_monitored' => false]);
        $this->control = SubUnit::create(['unit_id' => $this->unit->id, 'nama_layanan' => 'Desain Kontrol', 'deskripsi' => 'x', 'aktif' => true, 'is_monitored' => false]);
        $this->deadline = $this->field(301, $this->design->id, 'Deadline', 'tanggal', true);
        $this->controlDeadline = $this->field(311, $this->control->id, 'Deadline', 'tanggal', null);

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'admin']);
        $this->admin = Admin::create(['username' => 'fd_admin', 'email' => 'fd@example.com', 'name' => 'FD', 'password' => Hash::make('password')]);
        $this->admin->assignRole('Super Admin');
        $this->admin->units()->attach($this->unit->id);

        $cache = new \ReflectionProperty(SlaConfig::class, 'thresholdCache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);
        foreach ([['Sedang', 'respon', 60], ['Sedang', 'penyelesaian', 480]] as [$p, $j, $m]) {
            SlaConfig::create(['sub_unit_id' => null, 'priority' => $p, 'jenis' => $j, 'threshold_minutes' => $m]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function field(int $id, int $subUnitId, string $label, string $type, ?bool $flag): FormField
    {
        $f = new FormField();
        $f->forceFill(['id' => $id, 'sub_unit_id' => $subUnitId, 'label' => $label, 'tipe_field' => $type, 'wajib' => false, 'urutan' => $id, 'extends_resolution_sla' => $flag])->save();

        return $f;
    }

    private function submitWizard(SubUnit $subUnit, FormField $field, string $date): Ticket
    {
        $this->actingAs($this->user, 'web')->post(route('tiket.store'), [
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $subUnit->id,
            'form_data' => [(string) $field->id => $date],
        ])->assertRedirect();

        $known = $this->seen ?? [];
        $ticket = Ticket::with('slaTracking')->where('sub_unit_id', $subUnit->id)->whereNotIn('id', $known)->firstOrFail();
        $this->seen[] = $ticket->id;

        return $ticket;
    }

    public function test_later_form_date_extends_resolution_until_end_of_day_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));

        $control = $this->submitWizard($this->control, $this->controlDeadline, '2026-07-30')->slaTracking;
        $ticket = $this->submitWizard($this->design, $this->deadline, '2026-07-30')->slaTracking;

        $this->assertSame('2026-07-30 23:59:59', $ticket->sla_resolution_deadline->format('Y-m-d H:i:s'));
        $this->assertEquals($control->sla_resolution_deadline, $ticket->sla_resolution_policy_deadline);
        $this->assertEquals($control->sla_response_deadline, $ticket->sla_response_deadline);
        // Kontrol bernama "Deadline" tanpa penanda tidak memperpanjang.
        $this->assertNull($control->sla_resolution_form_deadline);
        $this->assertNotSame('2026-07-30 23:59:59', $control->sla_resolution_deadline->format('Y-m-d H:i:s'));
    }

    public function test_earlier_or_empty_form_date_never_shortens_sla(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));

        $control = $this->submitWizard($this->control, $this->controlDeadline, '')->slaTracking;
        $earlier = $this->submitWizard($this->design, $this->deadline, '2026-07-17')->slaTracking;
        $empty = $this->submitWizard($this->design, $this->deadline, '')->slaTracking;

        $policy = $control->sla_resolution_deadline;
        if ($policy->format('Y-m-d') > '2026-07-17') {
            $this->assertEquals($policy, $earlier->sla_resolution_deadline);
        }
        $this->assertTrue($earlier->sla_resolution_deadline->gte($policy));
        $this->assertEquals($policy, $empty->sla_resolution_deadline);
        $this->assertNull($empty->sla_resolution_form_deadline);
    }

    public function test_api_rejects_impossible_flagged_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson('/api/tickets', [
            'sub_unit_id' => $this->design->id,
            'form_data' => [(string) $this->deadline->id => '2026-02-30'],
        ])->assertStatus(422)->assertJsonValidationErrors('form_data.'.$this->deadline->id);

        $this->assertSame(0, Ticket::count());
    }

    public function test_breach_only_after_extended_deadline_and_removal_restores_policy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));
        $sla = $this->submitWizard($this->design, $this->deadline, '2026-07-30')->slaTracking;
        $policy = $sla->sla_resolution_policy_deadline->copy();
        $calc = new SlaCalculator;

        Carbon::setTestNow($policy->copy()->addHour());
        $calc->checkAndUpdateTier($sla->fresh());
        $this->assertFalse((bool) $sla->fresh()->is_resolution_breached);

        Carbon::setTestNow(Carbon::parse('2026-07-31 00:00:01'));
        $calc->checkAndUpdateTier($sla->fresh());
        $this->assertTrue((bool) $sla->fresh()->is_resolution_breached);

        $fresh = $sla->fresh();
        $calc->applyFormDeadline($fresh, null);
        $fresh->refresh();
        $this->assertEquals($policy, $fresh->sla_resolution_deadline);
        $this->assertNull($fresh->sla_resolution_policy_deadline);
    }

    public function test_builder_allows_only_one_flagged_date_field_per_service(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.peraturan-form.store', $this->design->id), ['label' => 'Tanggal Lain', 'tipe_field' => 'tanggal', 'wajib' => false, 'extends_resolution_sla' => true])
            ->assertSessionHasErrors('extends_resolution_sla');

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.peraturan-form.store', $this->control->id), ['label' => 'Waktu', 'tipe_field' => 'datetime', 'wajib' => false, 'extends_resolution_sla' => true])
            ->assertSessionHasErrors('extends_resolution_sla');

        // Subunit lain boleh punya pilihan sendiri.
        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.peraturan-form.update', $this->controlDeadline->id), ['label' => 'Deadline', 'tipe_field' => 'tanggal', 'wajib' => false, 'extends_resolution_sla' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($this->controlDeadline->fresh()->extends_resolution_sla);

        // Ganti tipe → penanda dibebaskan; pilihan pertama tetap utuh.
        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.peraturan-form.update', $this->controlDeadline->id), ['label' => 'Deadline', 'tipe_field' => 'teks_pendek', 'wajib' => false])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->controlDeadline->fresh()->extends_resolution_sla);
        $this->assertTrue($this->deadline->fresh()->extends_resolution_sla);
    }

    public function test_command_applies_form_date_to_existing_active_tickets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));
        FormField::whereKey($this->deadline->id)->update(['extends_resolution_sla' => null]);
        $ticket = $this->submitWizard($this->design, $this->deadline, '2026-07-30');
        $done = $this->submitWizard($this->design, $this->deadline, '2026-07-30');
        $done->update(['status' => 'selesai']);
        $policy = $ticket->slaTracking->sla_resolution_deadline->copy();
        $doneDeadline = $done->slaTracking->sla_resolution_deadline->copy();
        FormField::whereKey($this->deadline->id)->update(['extends_resolution_sla' => true]);

        $this->artisan('sla:apply-form-deadline', ['--dry-run' => true])->assertSuccessful();
        $this->assertEquals($policy, $ticket->slaTracking->fresh()->sla_resolution_deadline);

        $this->artisan('sla:apply-form-deadline')->assertSuccessful();
        $sla = $ticket->slaTracking->fresh();
        $this->assertSame('2026-07-30 23:59:59', $sla->sla_resolution_deadline->format('Y-m-d H:i:s'));
        $this->assertEquals($policy, $sla->sla_resolution_policy_deadline);
        $this->assertEquals($doneDeadline, $done->slaTracking->fresh()->sla_resolution_deadline);

        // Idempoten: jalan kedua tidak menggandakan.
        $this->artisan('sla:apply-form-deadline')->assertSuccessful();
        $this->assertEquals($sla->sla_resolution_deadline, $ticket->slaTracking->fresh()->sla_resolution_deadline);
    }

    public function test_command_dry_run_and_live_handle_pending_tickets_until_resumed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));
        FormField::whereKey($this->deadline->id)->update(['extends_resolution_sla' => null]);

        $pending = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->design->id,
            'form_data' => [(string) $this->deadline->id => '2026-07-30'],
            'status' => 'pending',
            'priority' => 'Sedang',
        ]);
        (new SlaCalculator)->createTracking($pending, null);
        $this->assertNull($pending->slaTracking->sla_resolution_deadline);

        FormField::whereKey($this->deadline->id)->update(['extends_resolution_sla' => true]);

        $this->assertSame(0, Artisan::call('sla:apply-form-deadline', ['--dry-run' => true]));
        $dryOut = Artisan::output();
        $this->assertStringContainsString((string) $pending->id, $dryOut);
        $this->assertStringContainsString('Pending (tanggal form disimpan, berlaku saat tiket diproses)', $dryOut);
        $this->assertStringContainsString('[DRY RUN] 1 tiket berubah, 0 dilewati, 0 sudah sesuai/tidak berubah.', $dryOut);
        $this->assertNull($pending->slaTracking->fresh()->sla_resolution_form_deadline);

        $this->assertSame(0, Artisan::call('sla:apply-form-deadline'));
        $liveOut = Artisan::output();
        $this->assertStringContainsString((string) $pending->id, $liveOut);
        $this->assertStringContainsString('1 tiket berubah, 0 dilewati, 0 sudah sesuai/tidak berubah.', $liveOut);

        $sla = $pending->slaTracking->fresh();
        $this->assertNull($sla->sla_resolution_deadline);
        $this->assertSame('2026-07-30 23:59:59', $sla->sla_resolution_form_deadline?->format('Y-m-d H:i:s'));

        // Idempoten pada run berikutnya.
        $this->assertSame(0, Artisan::call('sla:apply-form-deadline'));
        $secondOut = Artisan::output();
        $this->assertStringContainsString('0 tiket berubah, 0 dilewati, 1 sudah sesuai/tidak berubah.', $secondOut);
        $this->assertStringContainsString('sudah sesuai: 1 (pending: 1)', $secondOut);

        // Saat tiket mulai diproses, resumeSla() otomatis memperpanjang ke tanggal form.
        Carbon::setTestNow(Carbon::parse('2026-07-17 11:00:00'));
        $sla->setRelation('ticket', $pending->fresh());
        (new SlaCalculator)->resumeSla($sla, Carbon::now());
        $this->assertSame('2026-07-30 23:59:59', $sla->fresh()->sla_resolution_deadline?->format('Y-m-d H:i:s'));
    }

    public function test_command_dry_run_matches_live_when_form_date_shortened_or_unchecked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));
        $ticket = $this->submitWizard($this->design, $this->deadline, '2026-08-10');
        $policy = $ticket->slaTracking->sla_resolution_policy_deadline->copy();

        // 1) Tanggal form dimajukan ke 2026-08-03 (masih > policy).
        $ticket->update(['form_data' => [(string) $this->deadline->id => '2026-08-03']]);

        $this->assertSame(0, Artisan::call('sla:apply-form-deadline', ['--dry-run' => true]));
        $outDry1 = Artisan::output();
        $this->assertStringContainsString('2026-08-10 23:59:59', $outDry1);
        $this->assertStringContainsString('2026-08-03 23:59:59', $outDry1);
        $this->assertStringContainsString('Disesuaikan ke tanggal form baru', $outDry1);
        $this->assertSame('2026-08-10 23:59:59', $ticket->slaTracking->fresh()->sla_resolution_deadline->format('Y-m-d H:i:s'));

        $this->assertSame(0, Artisan::call('sla:apply-form-deadline'));
        $outLive1 = Artisan::output();
        $this->assertStringContainsString('2026-08-03 23:59:59', $outLive1);
        $this->assertSame('2026-08-03 23:59:59', $ticket->slaTracking->fresh()->sla_resolution_deadline->format('Y-m-d H:i:s'));

        // 2) Tanggal form dimajukan <= policy (2026-07-17) → kembali ke policy.
        $ticket->update(['form_data' => [(string) $this->deadline->id => '2026-07-17']]);

        $this->assertSame(0, Artisan::call('sla:apply-form-deadline', ['--dry-run' => true]));
        $outDry2 = Artisan::output();
        $this->assertStringContainsString('2026-08-03 23:59:59', $outDry2);
        $this->assertStringContainsString($policy->format('Y-m-d H:i:s'), $outDry2);
        $this->assertStringContainsString('Dikembalikan ke policy SLA', $outDry2);

        $this->assertSame(0, Artisan::call('sla:apply-form-deadline'));
        $this->assertEquals($policy, $ticket->slaTracking->fresh()->sla_resolution_deadline);

        // 3) Perpanjang lagi ke 2026-08-12 lalu matikan centang extends_resolution_sla → kembali ke policy.
        $ticket->update(['form_data' => [(string) $this->deadline->id => '2026-08-12']]);
        $this->assertSame(0, Artisan::call('sla:apply-form-deadline'));
        $this->assertSame('2026-08-12 23:59:59', $ticket->slaTracking->fresh()->sla_resolution_deadline->format('Y-m-d H:i:s'));

        FormField::whereKey($this->deadline->id)->update(['extends_resolution_sla' => null]);

        $this->assertSame(0, Artisan::call('sla:apply-form-deadline', ['--dry-run' => true]));
        $outDry3 = Artisan::output();
        $this->assertStringContainsString('2026-08-12 23:59:59', $outDry3);
        $this->assertStringContainsString($policy->format('Y-m-d H:i:s'), $outDry3);
        $this->assertStringContainsString('Dikembalikan ke policy SLA', $outDry3);
        $this->assertSame('2026-08-12 23:59:59', $ticket->slaTracking->fresh()->sla_resolution_deadline->format('Y-m-d H:i:s'));

        $this->assertSame(0, Artisan::call('sla:apply-form-deadline'));
        $sla = $ticket->slaTracking->fresh();
        $this->assertEquals($policy, $sla->sla_resolution_deadline);
        $this->assertNull($sla->sla_resolution_form_deadline);
        $this->assertNull($sla->sla_resolution_policy_deadline);
    }

    public function test_command_detects_and_repairs_desynced_resolution_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));
        $ticket = $this->submitWizard($this->design, $this->deadline, '2026-07-30');
        $policy = $ticket->slaTracking->sla_resolution_policy_deadline->copy();

        // Simulasikan drift: form deadline tersimpan, tetapi deadline efektif tertinggal di policy.
        TicketSlaTracking::whereKey($ticket->slaTracking->id)->update([
            'sla_resolution_deadline' => $policy,
        ]);

        $this->assertSame(0, Artisan::call('sla:apply-form-deadline', ['--dry-run' => true]));
        $dryOut = Artisan::output();
        $this->assertStringContainsString('[DRY RUN] 1 tiket berubah', $dryOut);
        $this->assertStringContainsString('2026-07-30 23:59:59', $dryOut);

        $this->assertSame(0, Artisan::call('sla:apply-form-deadline'));
        $this->assertStringContainsString('1 tiket berubah', Artisan::output());
        $this->assertSame('2026-07-30 23:59:59', $ticket->slaTracking->fresh()->sla_resolution_deadline->format('Y-m-d H:i:s'));
    }

    public function test_command_reports_skip_and_unchanged_reasons_transparently(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));

        // 1) Sudah punya tanggal form <= policy yang tersimpan.
        $withinPolicy = $this->submitWizard($this->design, $this->deadline, '2026-07-17');

        // 2) Tanggal form kosong.
        $empty = $this->submitWizard($this->design, $this->deadline, '');

        // 3) Aktif tanpa sla_tracking.
        $noTracking = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->design->id,
            'form_data' => [(string) $this->deadline->id => '2026-07-30'],
            'status' => 'open',
            'priority' => 'Sedang',
        ]);
        $this->seen[] = $noTracking->id;

        // 4) need_revision yang pernah solve (resolved_at !== null).
        $revision = $this->submitWizard($this->design, $this->deadline, '2026-07-30');
        $revision->update(['status' => 'need_revision']);
        $revision->slaTracking->update(['resolved_at' => Carbon::now()]);

        // 5) Tanggal tidak valid di form_data lama.
        $invalid = $this->submitWizard($this->design, $this->deadline, '2026-07-30');
        $invalid->update(['form_data' => [(string) $this->deadline->id => '2026-02-30']]);

        // 6) Baru pertama kali simpan tanggal form <= policy (dari null).
        FormField::whereKey($this->deadline->id)->update(['extends_resolution_sla' => null]);
        $newWithinPolicy = $this->submitWizard($this->design, $this->deadline, '2026-07-17');
        FormField::whereKey($this->deadline->id)->update(['extends_resolution_sla' => true]);

        $this->assertSame(0, Artisan::call('sla:apply-form-deadline', ['--dry-run' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString((string) $newWithinPolicy->id, $out);
        $this->assertStringContainsString('Tanggal form disimpan (<= policy SLA, deadline tetap)', $out);
        $this->assertStringContainsString('[DRY RUN] 1 tiket berubah, 3 dilewati, 2 sudah sesuai/tidak berubah.', $out);
        $this->assertStringContainsString('tanpa SLA tracking: 1', $out);
        $this->assertStringContainsString('sudah resolved: 1', $out);
        $this->assertStringContainsString('tanggal tidak valid: 1', $out);
        $this->assertStringContainsString('tanggal form kosong: 1', $out);
        $this->assertStringContainsString('sudah sesuai: 1 (<= policy: 1)', $out);

        $this->assertNotNull($withinPolicy->slaTracking->fresh());
        $this->assertNull($noTracking->slaTracking);
        $this->assertNull($empty->slaTracking->fresh()->sla_resolution_form_deadline);
    }

    public function test_trimmed_and_legacy_dates_parse_while_impossible_dates_still_fail(): void
    {
        $spaced = TicketSlaSchedule::extensionDeadline(
            TicketSlaSchedule::extensionInput($this->design, [$this->deadline], [(string) $this->deadline->id => ' 2026-07-30 '])
        );
        $this->assertSame('2026-07-30 23:59:59', $spaced?->format('Y-m-d H:i:s'));

        $slash = TicketSlaSchedule::extensionDeadline(
            TicketSlaSchedule::extensionInput($this->design, [$this->deadline], [(string) $this->deadline->id => '30/07/2026'])
        );
        $this->assertSame('2026-07-30 23:59:59', $slash?->format('Y-m-d H:i:s'));

        $this->expectException(ValidationException::class);
        TicketSlaSchedule::extensionDeadline(
            TicketSlaSchedule::extensionInput($this->design, [$this->deadline], [(string) $this->deadline->id => '2026-02-30'])
        );
    }
}
