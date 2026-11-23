<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\FormField;
use App\Models\SlaConfig;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\SlaCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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
}
