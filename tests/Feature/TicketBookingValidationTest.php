<?php

namespace Tests\Feature;

use App\Models\FormField;
use App\Models\RoomVehicleBooking;
use App\Models\SubUnit;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketBookingValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Unit $unit;
    private SubUnit $vehicleSubUnit;
    private FormField $assetField;
    private FormField $startDateField;
    private FormField $endDateField;
    private FormField $startTimeField;
    private FormField $endTimeField;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->unit = Unit::create([
            'nama_unit' => 'Sarpras',
            'deskripsi' => 'Unit Sarpras',
            'aktif' => true,
        ]);

        $this->vehicleSubUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Peminjaman Mobil',
            'deskripsi' => 'Peminjaman Kendaraan Dinas',
            'aktif' => true,
            'is_monitored' => true,
            'monitor_kategori' => 'Kendaraan',
        ]);

        $this->assetField = FormField::create([
            'sub_unit_id' => $this->vehicleSubUnit->id,
            'label' => 'Kendaraan',
            'tipe_field' => 'dropdown',
            'opsi' => ['APV', 'Innova Silver'],
            'wajib' => true,
            'urutan' => 1,
        ]);
        $this->startDateField = FormField::create([
            'sub_unit_id' => $this->vehicleSubUnit->id,
            'label' => 'Tanggal Pinjam',
            'tipe_field' => 'tanggal',
            'wajib' => true,
            'urutan' => 2,
        ]);
        $this->endDateField = FormField::create([
            'sub_unit_id' => $this->vehicleSubUnit->id,
            'label' => 'Tanggal Kembali',
            'tipe_field' => 'tanggal',
            'wajib' => true,
            'urutan' => 3,
        ]);
        $this->startTimeField = FormField::create([
            'sub_unit_id' => $this->vehicleSubUnit->id,
            'label' => 'Jam Mulai',
            'tipe_field' => 'waktu',
            'wajib' => true,
            'urutan' => 4,
        ]);
        $this->endTimeField = FormField::create([
            'sub_unit_id' => $this->vehicleSubUnit->id,
            'label' => 'Jam Selesai',
            'tipe_field' => 'waktu',
            'wajib' => true,
            'urutan' => 5,
        ]);

        $this->vehicleSubUnit->update([
            'monitor_asset_field_id' => $this->assetField->id,
            'monitor_date_field_id' => $this->startDateField->id,
            'monitor_end_date_field_id' => $this->endDateField->id,
            'monitor_start_field_id' => $this->startTimeField->id,
            'monitor_end_field_id' => $this->endTimeField->id,
        ]);
    }

    public function test_api_rejects_past_date_booking(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 13, 10, 0, 0));
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson('/api/tickets', [
            'sub_unit_id' => $this->vehicleSubUnit->id,
            'form_data' => [
                (string) $this->assetField->id => 'APV',
                (string) $this->startDateField->id => '2026-11-10',
                (string) $this->endDateField->id => '2026-11-10',
                (string) $this->startTimeField->id => '09:00',
                (string) $this->endTimeField->id => '12:00',
            ],
        ])->assertStatus(422);

        $this->assertEquals(0, RoomVehicleBooking::count());
        Carbon::setTestNow();
    }

    public function test_api_rejects_end_time_before_start_time_on_same_day(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 13, 10, 0, 0));
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson('/api/tickets', [
            'sub_unit_id' => $this->vehicleSubUnit->id,
            'form_data' => [
                (string) $this->assetField->id => 'APV',
                (string) $this->startDateField->id => '2026-11-14',
                (string) $this->endDateField->id => '2026-11-14',
                (string) $this->startTimeField->id => '09:20',
                (string) $this->endTimeField->id => '09:01',
            ],
        ])->assertStatus(422);

        $this->assertEquals(0, RoomVehicleBooking::count());
        Carbon::setTestNow();
    }

    public function test_api_rejects_end_date_before_start_date(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 13, 10, 0, 0));
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson('/api/tickets', [
            'sub_unit_id' => $this->vehicleSubUnit->id,
            'form_data' => [
                (string) $this->assetField->id => 'APV',
                (string) $this->startDateField->id => '2026-11-14',
                (string) $this->endDateField->id => '2026-11-13',
                (string) $this->startTimeField->id => '09:00',
                (string) $this->endTimeField->id => '16:31',
            ],
        ])->assertStatus(422);

        $this->assertEquals(0, RoomVehicleBooking::count());
        Carbon::setTestNow();
    }

    public function test_api_allows_valid_multi_day_booking(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 13, 10, 0, 0));
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson('/api/tickets', [
            'sub_unit_id' => $this->vehicleSubUnit->id,
            'form_data' => [
                (string) $this->assetField->id => 'APV',
                (string) $this->startDateField->id => '2026-11-14',
                (string) $this->endDateField->id => '2026-11-15',
                (string) $this->startTimeField->id => '09:00',
                (string) $this->endTimeField->id => '08:00',
            ],
        ])->assertCreated();

        $this->assertEquals(1, RoomVehicleBooking::count());
        Carbon::setTestNow();
    }
}
