<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Unit;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use App\Models\SlaConfig;
use App\Models\Admin;
use App\Services\SlaCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class SlaCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Unit $unit;
    private SubUnit $subUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->unit = Unit::create([
            'nama_unit' => 'IT Support',
            'deskripsi' => 'Unit IT Support',
            'aktif' => true,
        ]);

        $this->subUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Jaringan dan Internet',
            'deskripsi' => 'Jaringan dan Internet',
            'aktif' => true,
        ]);
    }

    public function test_working_minutes_same_day(): void
    {
        $calculator = new SlaCalculator();
        $start = Carbon::parse('2026-07-13 09:00:00'); // Senin
        $end = Carbon::parse('2026-07-13 11:00:00');
        $minutes = $calculator->getWorkingMinutesBetween($start, $end);
        $this->assertEquals(120, $minutes);
    }

    public function test_working_minutes_cross_day(): void
    {
        $calculator = new SlaCalculator();
        $start = Carbon::parse('2026-07-17 15:00:00'); // Jumat
        $end = Carbon::parse('2026-07-20 09:00:00'); // Senin
        $minutes = $calculator->getWorkingMinutesBetween($start, $end);
        $this->assertEquals(120, $minutes);
    }

    public function test_working_minutes_skip_weekend(): void
    {
        $calculator = new SlaCalculator();
        $start = Carbon::parse('2026-07-17 08:00:00'); // Jumat
        $end = Carbon::parse('2026-07-20 16:00:00'); // Senin
        $minutes = $calculator->getWorkingMinutesBetween($start, $end);
        $this->assertEquals(960, $minutes);
    }

    public function test_add_working_minutes_simple(): void
    {
        $calculator = new SlaCalculator();
        $start = Carbon::parse('2026-07-13 08:00:00'); // Senin
        $result = $calculator->addWorkingMinutes($start, 120);
        $this->assertEquals('2026-07-13 10:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_add_working_minutes_cross_day(): void
    {
        $calculator = new SlaCalculator();
        $start = Carbon::parse('2026-07-13 15:00:00'); // Senin
        $result = $calculator->addWorkingMinutes($start, 120);
        $this->assertEquals('2026-07-14 09:00:00', $result->format('Y-m-d H:i:s')); // Selasa
    }

    public function test_add_working_minutes_skip_weekend(): void
    {
        $calculator = new SlaCalculator();
        $start = Carbon::parse('2026-07-17 15:00:00'); // Jumat
        $result = $calculator->addWorkingMinutes($start, 120);
        $this->assertEquals('2026-07-20 09:00:00', $result->format('Y-m-d H:i:s')); // Senin
    }

    public function test_pause_sla(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => [],
            'status' => 'on_proses',
        ]);

        $sla = TicketSlaTracking::create([
            'ticket_id' => $ticket->id,
            'sla_response_deadline' => Carbon::now()->addHours(2),
            'sla_resolution_deadline' => Carbon::now()->addHours(4),
            'paused_at' => null,
            'total_paused_minutes' => 0,
            'current_tier' => 0,
        ]);

        $calculator = new SlaCalculator();

        Carbon::setTestNow($now = Carbon::parse('2026-07-13 10:00:00'));

        $calculator->pauseSla($sla);

        $this->assertNotNull($sla->paused_at);
        $this->assertEquals($now->toDateTimeString(), $sla->paused_at->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_resume_sla(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => [],
            'status' => 'pending',
        ]);

        $pausedAt = Carbon::parse('2026-07-13 10:00:00');
        $now = Carbon::parse('2026-07-13 10:30:00');

        $initialResponseDeadline = Carbon::parse('2026-07-13 12:00:00');
        $initialResolutionDeadline = Carbon::parse('2026-07-13 14:00:00');

        $sla = TicketSlaTracking::create([
            'ticket_id' => $ticket->id,
            'sla_response_deadline' => $initialResponseDeadline,
            'sla_resolution_deadline' => $initialResolutionDeadline,
            'paused_at' => $pausedAt,
            'total_paused_minutes' => 10,
            'current_tier' => 0,
        ]);

        $calculator = new SlaCalculator();

        Carbon::setTestNow($now);

        $calculator->resumeSla($sla);

        $this->assertNull($sla->paused_at);
        $this->assertEquals(40, $sla->total_paused_minutes);
        $this->assertEquals('2026-07-13 12:30:00', $sla->sla_response_deadline->toDateTimeString());
        $this->assertEquals('2026-07-13 14:30:00', $sla->sla_resolution_deadline->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_check_tier_escalation(): void
    {
        SlaConfig::create([
            'sub_unit_id' => $this->subUnit->id,
            'priority' => 'Sedang',
            'jenis' => 'respon',
            'threshold_minutes' => 30,
        ]);
        SlaConfig::create([
            'sub_unit_id' => $this->subUnit->id,
            'priority' => 'Sedang',
            'jenis' => 'penyelesaian',
            'threshold_minutes' => 120,
        ]);

        $createdAt = Carbon::parse('2026-07-13 08:30:00'); // Senin
        $ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => [],
            'status' => 'on_proses',
            'priority' => 'Sedang',
        ]);
        $ticket->created_at = $createdAt;
        $ticket->save();

        $sla = TicketSlaTracking::create([
            'ticket_id' => $ticket->id,
            'sla_response_deadline' => $createdAt->copy()->addHours(2),
            'sla_resolution_deadline' => $createdAt->copy()->addHours(4),
            'paused_at' => null,
            'total_paused_minutes' => 0,
            'is_response_breached' => false,
            'is_resolution_breached' => false,
        ]);

        $calculator = new SlaCalculator();

        Carbon::setTestNow(Carbon::parse('2026-07-13 09:35:00'));

        $calculator->checkAndUpdateTier($sla);

        $this->assertTrue($sla->fresh()->is_response_breached);
        $this->assertFalse($sla->fresh()->is_resolution_breached);

        Carbon::setTestNow();
    }

    public function test_endpoint_updates_sla_configs_successfully(): void
    {
        if (Role::where('name', 'admin')->where('guard_name', 'admin')->doesntExist()) {
            Role::create(['name' => 'admin', 'guard_name' => 'admin']);
        }

        $admin = Admin::create([
            'username' => 'admin_test',
            'email' => 'admin_test@test.com',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'admin')
            ->put(route('admin.sla-config.update'), [
                'configs' => [
                    ['sub_unit_id' => null, 'priority' => 'Rendah', 'jenis' => 'respon', 'threshold_minutes' => 30],
                    ['sub_unit_id' => null, 'priority' => 'Sedang', 'jenis' => 'respon', 'threshold_minutes' => 60],
                    ['sub_unit_id' => null, 'priority' => 'Tinggi', 'jenis' => 'respon', 'threshold_minutes' => 120],
                ]
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sla_configs', [
            'sub_unit_id' => null,
            'priority' => 'Rendah',
            'jenis' => 'respon',
            'threshold_minutes' => 30,
        ]);
        $this->assertDatabaseHas('sla_configs', [
            'sub_unit_id' => null,
            'priority' => 'Sedang',
            'jenis' => 'respon',
            'threshold_minutes' => 60,
        ]);
        $this->assertDatabaseHas('sla_configs', [
            'sub_unit_id' => null,
            'priority' => 'Tinggi',
            'jenis' => 'respon',
            'threshold_minutes' => 120,
        ]);
    }

    public function test_endpoint_validation_fails_if_priority_invalid(): void
    {
        if (Role::where('name', 'admin')->where('guard_name', 'admin')->doesntExist()) {
            Role::create(['name' => 'admin', 'guard_name' => 'admin']);
        }

        $admin = Admin::create([
            'username' => 'admin_test2',
            'email' => 'admin_test2@test.com',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'admin')
            ->put(route('admin.sla-config.update'), [
                'configs' => [
                    ['sub_unit_id' => null, 'priority' => 'InvalidPriority', 'jenis' => 'respon', 'threshold_minutes' => 30],
                ]
            ]);

        $response->assertSessionHasErrors(['configs.0.priority']);
    }

    public function test_endpoint_validation_fails_if_threshold_less_than_one(): void
    {
        if (Role::where('name', 'admin')->where('guard_name', 'admin')->doesntExist()) {
            Role::create(['name' => 'admin', 'guard_name' => 'admin']);
        }

        $admin = Admin::create([
            'username' => 'admin_test3',
            'email' => 'admin_test3@test.com',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'admin')
            ->put(route('admin.sla-config.update'), [
                'configs' => [
                    ['sub_unit_id' => null, 'priority' => 'Rendah', 'jenis' => 'respon', 'threshold_minutes' => 0], // Invalid
                ]
            ]);

        $response->assertSessionHasErrors(['configs.0.threshold_minutes']);
    }
}
