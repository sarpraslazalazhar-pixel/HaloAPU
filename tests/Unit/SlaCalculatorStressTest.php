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
use App\Models\SystemConfig;
use App\Services\SlaCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class SlaCalculatorStressTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Unit $unit;
    private SubUnit $subUnit;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->unit = Unit::create([
            'nama_unit' => 'IT Support Stress',
            'deskripsi' => 'Unit IT Support Stress Test',
            'aktif' => true,
        ]);

        $this->subUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Jaringan Stress',
            'deskripsi' => 'Jaringan Stress Test',
            'aktif' => true,
        ]);

        if (Role::where('name', 'admin')->where('guard_name', 'admin')->doesntExist()) {
            Role::create(['name' => 'admin', 'guard_name' => 'admin']);
        }

        $this->admin = Admin::create([
            'username' => 'admin_stress',
            'email' => 'admin_stress@test.com',
            'password' => bcrypt('password'),
        ]);
        $this->admin->assignRole('admin');
    }

    /**
     * Test SlaCalculator when start time is equal to end time.
     */
    public function test_calculator_start_equals_end(): void
    {
        $calculator = new SlaCalculator();
        $start = Carbon::parse('2026-07-13 09:00:00');
        $end = Carbon::parse('2026-07-13 09:00:00');
        $minutes = $calculator->getWorkingMinutesBetween($start, $end);
        $this->assertEquals(0, $minutes);
    }

    /**
     * Test SlaCalculator when start time is after end time.
     */
    public function test_calculator_start_greater_than_end(): void
    {
        $calculator = new SlaCalculator();
        $start = Carbon::parse('2026-07-13 10:00:00');
        $end = Carbon::parse('2026-07-13 09:00:00');
        $minutes = $calculator->getWorkingMinutesBetween($start, $end);
        $this->assertEquals(0, $minutes);
    }

    /**
     * Test SlaCalculator when start is exactly at work end (16:00).
     */
    public function test_calculator_start_exactly_at_work_end(): void
    {
        $calculator = new SlaCalculator();
        $start = Carbon::parse('2026-07-13 16:00:00'); // Monday 16:00
        $end = Carbon::parse('2026-07-13 17:00:00');   // Monday 17:00
        $minutes = $calculator->getWorkingMinutesBetween($start, $end);
        $this->assertEquals(0, $minutes);
    }

    /**
     * Test SlaCalculator when end is exactly at work start (08:00).
     */
    public function test_calculator_end_exactly_at_work_start(): void
    {
        $calculator = new SlaCalculator();
        $start = Carbon::parse('2026-07-13 07:00:00'); // Monday 07:00
        $end = Carbon::parse('2026-07-13 08:00:00');   // Monday 08:00
        $minutes = $calculator->getWorkingMinutesBetween($start, $end);
        $this->assertEquals(0, $minutes);
    }

    /**
     * Test SlaCalculator when start is 1 second before work end.
     */
    public function test_calculator_start_second_before_work_end(): void
    {
        $calculator = new SlaCalculator();
        $start = Carbon::parse('2026-07-13 15:59:59'); // Monday 15:59:59
        $end = Carbon::parse('2026-07-13 16:00:00');   // Monday 16:00:00
        $minutes = $calculator->getWorkingMinutesBetween($start, $end);
        // Note: diffInMinutes by default returns absolute difference of complete minutes
        $this->assertEquals(0, $minutes);
    }

    /**
     * Test SlaCalculator when start is 1 minute before work end.
     */
    public function test_calculator_start_minute_before_work_end(): void
    {
        $calculator = new SlaCalculator();
        $start = Carbon::parse('2026-07-13 15:59:00');
        $end = Carbon::parse('2026-07-13 16:00:00');
        $minutes = $calculator->getWorkingMinutesBetween($start, $end);
        $this->assertEquals(1, $minutes);
    }

    /**
     * Test batch update endpoint: duplicate configs for the same tier in the same request.
     */
    public function test_endpoint_validation_with_duplicate_tiers_in_request(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->put(route('admin.sla-config.update'), [
                'configs' => [
                    ['sub_unit_id' => null, 'tier' => 1, 'jenis' => 'respon', 'threshold_minutes' => 30],
                    ['sub_unit_id' => null, 'tier' => 1, 'jenis' => 'respon', 'threshold_minutes' => 40],
                    ['sub_unit_id' => null, 'tier' => 2, 'jenis' => 'respon', 'threshold_minutes' => 60],
                    ['sub_unit_id' => null, 'tier' => 3, 'jenis' => 'respon', 'threshold_minutes' => 120],
                ]
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sla_configs', [
            'sub_unit_id' => null,
            'tier' => 1,
            'jenis' => 'respon',
            'threshold_minutes' => 40,
        ]);
    }

    /**
     * Test batch update endpoint: partial update causing database inconsistency.
     * We have Tier 1 = 30, Tier 2 = 60, Tier 3 = 120 in DB.
     * We submit update for Tier 1 = 80, but do NOT submit Tier 2.
     * DB will have Tier 1 = 80 and Tier 2 = 60, which is inconsistent (Tier 1 > Tier 2).
     */
    public function test_endpoint_validation_partial_update_inconsistency(): void
    {
        SlaConfig::create(['sub_unit_id' => null, 'tier' => 1, 'jenis' => 'respon', 'threshold_minutes' => 30]);
        SlaConfig::create(['sub_unit_id' => null, 'tier' => 2, 'jenis' => 'respon', 'threshold_minutes' => 60]);
        SlaConfig::create(['sub_unit_id' => null, 'tier' => 3, 'jenis' => 'respon', 'threshold_minutes' => 120]);

        $response = $this->actingAs($this->admin, 'admin')
            ->put(route('admin.sla-config.update'), [
                'configs' => [
                    ['sub_unit_id' => null, 'tier' => 1, 'jenis' => 'respon', 'threshold_minutes' => 80],
                ]
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $tier1 = SlaConfig::whereNull('sub_unit_id')->where('tier', 1)->where('jenis', 'respon')->first();
        $tier2 = SlaConfig::whereNull('sub_unit_id')->where('tier', 2)->where('jenis', 'respon')->first();

        $this->assertGreaterThan($tier2->threshold_minutes, $tier1->threshold_minutes);
    }
}
