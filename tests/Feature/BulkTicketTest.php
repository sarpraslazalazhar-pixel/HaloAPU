<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\FormField;
use App\Models\RoomVehicleBooking;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TicketCreatedAdminNotification;
use App\Notifications\TicketCreatedUserNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BulkTicketTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;
    private User $user;
    private Unit $unit;
    private Unit $otherUnit;
    private SubUnit $subUnit;
    private SubUnit $emptySubUnit;
    private FormField $requiredField;
    private FormField $uploadField;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('public');
        Carbon::setTestNow(Carbon::parse('2026-07-17 10:00:00'));

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'admin']);

        \Spatie\Permission\Models\Permission::firstOrCreate([
            'name' => 'akses-assign-operator',
            'guard_name' => 'admin',
        ]);
        Role::findByName('Admin', 'admin')->syncPermissions(['akses-assign-operator']);

        $this->admin = Admin::factory()->create(['username' => 'bulk-admin']);
        $this->admin->assignRole('Admin');

        $this->user = User::factory()->create(['username' => 'bulk-user', 'email' => 'bulk-user@example.test']);

        $this->unit = Unit::create(['nama_unit' => 'Umum', 'deskripsi' => 'Unit', 'aktif' => true]);
        $this->otherUnit = Unit::create(['nama_unit' => 'Lain', 'deskripsi' => 'Unit lain', 'aktif' => true]);

        $this->subUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Kerusakan Umum',
            'deskripsi' => 'Layanan umum',
            'aktif' => true,
            'is_monitored' => false,
        ]);

        $this->emptySubUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Tanpa Isian',
            'deskripsi' => 'Layanan tanpa field',
            'aktif' => true,
            'is_monitored' => false,
        ]);

        $this->requiredField = FormField::create([
            'sub_unit_id' => $this->subUnit->id,
            'label' => 'Keterangan',
            'tipe_field' => 'teks_pendek',
            'wajib' => true,
            'urutan' => 1,
        ]);

        $this->uploadField = FormField::create([
            'sub_unit_id' => $this->subUnit->id,
            'label' => 'Dokumen Pendukung',
            'tipe_field' => 'upload_file',
            'wajib' => false,
            'urutan' => 2,
        ]);
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'priority' => 'Sedang',
            'form_data' => json_encode([(string) $this->requiredField->id => 'AC bocor']),
        ], $overrides);
    }

    public function test_hanya_admin_yang_dapat_membuka_dan_mengirim_bulk(): void
    {
        $operator = Admin::factory()->create(['username' => 'bulk-operator']);
        $operator->assignRole('Operator');

        $superadmin = Admin::factory()->create(['username' => 'bulk-super']);
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'admin']);
        $superadmin->assignRole('superadmin');

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.tickets.bulk.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/BulkCreate')
                ->where('maxTickets', 5)
                ->has('unitList')
                ->has('priorities', 4));

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.tiket.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canBulkCreate', true));

        $this->actingAs($operator, 'admin')
            ->get(route('admin.tickets.bulk.create'))
            ->assertForbidden();

        $this->actingAs($superadmin, 'admin')
            ->get(route('admin.tickets.bulk.create'))
            ->assertOk();

        $this->actingAs($superadmin, 'admin')
            ->get(route('admin.tiket.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canBulkCreate', true));

        $this->app['auth']->forgetGuards();

        $this->actingAs($this->user, 'web')
            ->get(route('admin.tickets.bulk.create'))
            ->assertForbidden();

        $this->actingAs($this->user, 'web')
            ->post(route('admin.tickets.bulk.store'), ['tickets' => [$this->row()]])
            ->assertForbidden();

        $this->actingAs($operator, 'admin')
            ->get(route('admin.tiket.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canBulkCreate', false));

        $this->app['auth']->forgetGuards();

        $this->post(route('admin.tickets.bulk.store'), ['tickets' => [$this->row()]])
            ->assertRedirect(route('login'));

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketAttachment::count());
    }

    public function test_jumlah_dan_validasi_baris_menolak_tanpa_menyimpan(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.tickets.bulk.store'), ['tickets' => []])
            ->assertSessionHasErrors('tickets');

        $rows = array_fill(0, 6, $this->row());

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.tickets.bulk.store'), ['tickets' => $rows])
            ->assertSessionHasErrors('tickets');

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.tickets.bulk.store'), ['tickets' => [
                $this->row(),
                $this->row(['form_data' => json_encode([])]),
                $this->row(['unit_id' => $this->otherUnit->id]),
            ]])
            ->assertRedirect(route('admin.tickets.bulk.create'))
            ->assertSessionHasErrors([
                'tickets.1.form_data.'.$this->requiredField->id,
                'tickets.2.sub_unit_id',
            ]);

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketAttachment::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_lampiran_wajib_sesuai_target_dan_batas_ukuran(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.tickets.bulk.store'), ['tickets' => [
                $this->row(['attachment_target' => 'general']),
                array_merge($this->row(), [
                    'attachment' => UploadedFile::fake()->image('besar.png', 10, 10)->size(2049),
                    'attachment_target' => 'general',
                ]),
            ]])
            ->assertSessionHasErrors([
                'tickets.0.attachment',
                'tickets.1.attachment',
            ]);

        $this->assertSame(0, Ticket::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_batch_dengan_key_string_ditolak_tanpa_500(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.tickets.bulk.store'), [
                'tickets' => ['baris-pertama' => $this->row()],
            ])
            ->assertRedirect(route('admin.tickets.bulk.create'))
            ->assertSessionHasErrors('tickets');

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketAttachment::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_upload_wajib_menolak_target_general_dan_menerima_id_field(): void
    {
        $wajibSubUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Layanan Berkas Wajib',
            'deskripsi' => 'Butuh dokumen',
            'aktif' => true,
            'is_monitored' => false,
        ]);

        $wajibUpload = FormField::create([
            'sub_unit_id' => $wajibSubUnit->id,
            'label' => 'Dokumen Wajib',
            'tipe_field' => 'upload_file',
            'wajib' => true,
            'urutan' => 1,
        ]);

        $wajibRow = fn (array $overrides = []) => array_merge([
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $wajibSubUnit->id,
            'priority' => 'Sedang',
            'form_data' => json_encode([]),
        ], $overrides);

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.tickets.bulk.store'), ['tickets' => [
                $wajibRow(['attachment' => UploadedFile::fake()->image('tanpa-target.png', 10, 10)]),
                $wajibRow([
                    'attachment' => UploadedFile::fake()->image('general.png', 10, 10),
                    'attachment_target' => 'general',
                ]),
            ]])
            ->assertRedirect(route('admin.tickets.bulk.create'))
            ->assertSessionHasErrors([
                'tickets.0.attachment',
                'tickets.1.attachment',
            ]);

        $this->assertSame(0, Ticket::count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.tickets.bulk.store'), ['tickets' => [
                $wajibRow([
                    'attachment' => UploadedFile::fake()->image('valid.png', 10, 10),
                    'attachment_target' => (string) $wajibUpload->id,
                ]),
            ]])
            ->assertRedirect(route('admin.tiket.index', ['tab' => 'saya_ajukan']));

        $attachment = TicketAttachment::firstOrFail();

        $this->assertSame($wajibUpload->id, $attachment->field_id);
        $this->assertSame(1, (int) $attachment->wajib);
        $this->assertTrue(Storage::disk('public')->exists($attachment->file_path));
    }

    public function test_lima_tiket_tersimpan_dan_terlihat_di_api(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.tickets.bulk.store'), ['tickets' => [
                $this->row(['priority' => 'Tinggi']),
                array_merge($this->row(['priority' => 'Rendah']), [
                    'attachment' => UploadedFile::fake()->image('umum.png', 10, 10),
                    'attachment_target' => 'general',
                ]),
                $this->row(),
                array_merge($this->row(), [
                    'attachment' => UploadedFile::fake()->image('dokumen.png', 10, 10),
                    'attachment_target' => (string) $this->uploadField->id,
                ]),
                $this->row([
                    'sub_unit_id' => $this->emptySubUnit->id,
                    'form_data' => json_encode([]),
                ]),
            ]])
            ->assertRedirect(route('admin.tiket.index', ['tab' => 'saya_ajukan']))
            ->assertSessionHas('success', '5 tiket berhasil diajukan.');

        $tickets = Ticket::with('slaTracking')->get();

        $this->assertCount(5, $tickets);
        $this->assertCount(5, TicketLog::where('aksi', 'dibuat')->get());
        $this->assertCount(5, TicketSlaTracking::all());

        foreach ($tickets as $ticket) {
            $this->assertSame($this->admin->id, $ticket->created_by_admin_id);
            $this->assertSame('web', $ticket->source);
            $this->assertSame('open', $ticket->status);
            $this->assertNotNull($ticket->slaTracking);
        }

        // IDs are random nine-digit values, so compare priorities independently of row order.
        $this->assertEqualsCanonicalizing(
            ['Tinggi', 'Rendah', 'Sedang', 'Sedang', 'Sedang'],
            $tickets->pluck('priority')->all()
        );
        $filled = $tickets->firstWhere('sub_unit_id', $this->subUnit->id);
        $this->assertNotNull($filled);
        $this->assertSame('AC bocor', $filled->form_data[(string) $this->requiredField->id]);

        $attachments = TicketAttachment::all();

        $this->assertCount(2, $attachments);
        $general = $attachments->firstWhere('field_id', null);
        $fieldFile = $attachments->firstWhere('field_id', $this->uploadField->id);

        $this->assertNotNull($general);
        $this->assertNotNull($fieldFile);
        $this->assertTrue(Storage::disk('public')->exists($general->file_path));
        $this->assertTrue(Storage::disk('public')->exists($fieldFile->file_path));

        Sanctum::actingAs($this->admin, ['*']);

        $apiIds = collect($this->getJson('/api/tickets?tab=saya_ajukan&per_page=20')
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing(
            $tickets->pluck('formatted_id')->all(),
            $apiIds
        );

        Sanctum::actingAs($this->user, ['*']);

        $userIds = collect($this->getJson('/api/tickets?per_page=20')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame([], array_values(array_intersect($apiIds, $userIds)));
    }

    public function test_kegagalan_insert_ketiga_membatalkan_semua_dan_file(): void
    {
        Storage::disk('public')->put('sentinel.txt', 'tetap');

        DB::unprepared(
            "CREATE TEMP TRIGGER bulk_fail_third BEFORE INSERT ON tickets ".
            "WHEN NEW.priority = 'Urgen' BEGIN SELECT RAISE(ABORT, 'bulk QA: row 3'); END;"
        );

        try {
            $this->actingAs($this->admin, 'admin')
                ->post(route('admin.tickets.bulk.store'), ['tickets' => [
                    array_merge($this->row(), [
                        'attachment' => UploadedFile::fake()->image('satu.png', 10, 10),
                        'attachment_target' => 'general',
                    ]),
                    array_merge($this->row(), [
                        'attachment' => UploadedFile::fake()->image('dua.png', 10, 10),
                        'attachment_target' => 'general',
                    ]),
                    $this->row(['priority' => 'Urgen']),
                ]])
                ->assertRedirect(route('admin.tickets.bulk.create'))
                ->assertSessionHas('error', 'Gagal menyimpan tiket. Silakan coba lagi.');
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS bulk_fail_third');
        }

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketAttachment::count());
        $this->assertSame(0, TicketLog::count());
        $this->assertSame(0, TicketSlaTracking::count());
        $this->assertSame(['sentinel.txt'], Storage::disk('public')->allFiles());
    }

    public function test_baris_booking_bentrok_membatalkan_batch(): void
    {
        $vehicle = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Peminjaman Mobil',
            'deskripsi' => 'Kendaraan',
            'aktif' => true,
            'is_monitored' => true,
            'monitor_kategori' => 'Kendaraan',
        ]);

        $asset = FormField::create([
            'sub_unit_id' => $vehicle->id,
            'label' => 'Kendaraan',
            'tipe_field' => 'dropdown',
            'opsi' => ['APV', 'Innova'],
            'wajib' => true,
            'urutan' => 1,
        ]);
        $date = FormField::create([
            'sub_unit_id' => $vehicle->id,
            'label' => 'Tanggal Pinjam',
            'tipe_field' => 'tanggal',
            'wajib' => true,
            'urutan' => 2,
        ]);
        $start = FormField::create([
            'sub_unit_id' => $vehicle->id,
            'label' => 'Jam Mulai',
            'tipe_field' => 'waktu',
            'wajib' => true,
            'urutan' => 3,
        ]);
        $end = FormField::create([
            'sub_unit_id' => $vehicle->id,
            'label' => 'Jam Selesai',
            'tipe_field' => 'waktu',
            'wajib' => true,
            'urutan' => 4,
        ]);

        $vehicle->update([
            'monitor_asset_field_id' => $asset->id,
            'monitor_date_field_id' => $date->id,
            'monitor_start_field_id' => $start->id,
            'monitor_end_field_id' => $end->id,
        ]);

        $formData = json_encode([
            (string) $asset->id => 'APV',
            (string) $date->id => '2026-07-20',
            (string) $start->id => '10:00',
            (string) $end->id => '11:00',
        ]);

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.tickets.bulk.store'), ['tickets' => [
                ['unit_id' => $this->unit->id, 'sub_unit_id' => $vehicle->id, 'priority' => 'Sedang', 'form_data' => $formData],
                ['unit_id' => $this->unit->id, 'sub_unit_id' => $vehicle->id, 'priority' => 'Sedang', 'form_data' => $formData],
            ]])
            ->assertRedirect(route('admin.tickets.bulk.create'))
            ->assertSessionHasErrors([
                'tickets.1.form_data.'.$asset->id => 'Aset ini sudah dipesan pada jam tersebut.',
            ]);

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, RoomVehicleBooking::count());
    }

    private function userRow(array $overrides = []): array
    {
        return array_merge([
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => json_encode([(string) $this->requiredField->id => 'Butuh bantuan']),
        ], $overrides);
    }

    public function test_user_dapat_membuka_halaman_bulk_create_dan_guest_ditolak(): void
    {
        $this->get(route('tiket.bulk.create'))
            ->assertRedirect(route('login'));

        $this->actingAs($this->user, 'web')
            ->get(route('tiket.bulk.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Tiket/BulkCreate')
                ->where('maxTickets', 5)
                ->has('unitList'));
    }

    public function test_user_dapat_mengajukan_lima_tiket_beserta_profil_sla_dan_notifikasi(): void
    {
        $divisiId = DB::table('org_divisi')->insertGetId(['nama_divisi' => 'Operasional', 'created_at' => now(), 'updated_at' => now()]);
        $orgUnitId = DB::table('org_unit')->insertGetId(['divisi_id' => $divisiId, 'nama_unit_organisasi' => 'Layanan Internal', 'created_at' => now(), 'updated_at' => now()]);
        $jabatanId = DB::table('org_jabatan')->insertGetId(['nama_jabatan' => 'Staf', 'urutan' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->user->update([
            'divisi_id' => $divisiId,
            'org_unit_id' => $orgUnitId,
            'jabatan_id' => $jabatanId,
        ]);
        $this->admin->units()->attach($this->unit->id);

        $this->actingAs($this->user, 'web')
            ->post(route('tiket.bulk.store'), ['tickets' => [
                $this->userRow(),
                array_merge($this->userRow(), [
                    'attachment' => UploadedFile::fake()->image('user-umum.png', 10, 10),
                    'attachment_target' => 'general',
                ]),
                $this->userRow(),
                $this->userRow([
                    'sub_unit_id' => $this->emptySubUnit->id,
                    'form_data' => json_encode([]),
                ]),
                $this->userRow(),
            ]])
            ->assertRedirect(route('tiket.riwayat'))
            ->assertSessionHas('success', '5 tiket berhasil diajukan.');

        $tickets = Ticket::with('slaTracking')->get();
        $this->assertCount(5, $tickets);
        $this->assertCount(5, TicketLog::where('aksi', 'dibuat')->get());
        $this->assertCount(5, TicketSlaTracking::all());

        foreach ($tickets as $ticket) {
            $this->assertSame($this->user->id, $ticket->user_id);
            $this->assertNull($ticket->created_by_admin_id);
            $this->assertSame($divisiId, $ticket->divisi_id);
            $this->assertSame($orgUnitId, $ticket->org_unit_id);
            $this->assertSame($jabatanId, $ticket->jabatan_id);
            $this->assertNull($ticket->priority);
            $this->assertSame('open', $ticket->status);
            $this->assertNotNull($ticket->slaTracking);
        }

        Notification::assertSentToTimes($this->user, TicketCreatedUserNotification::class, 5);
        Notification::assertSentToTimes($this->admin, TicketCreatedAdminNotification::class, 5);
    }

    public function test_user_mengirim_enam_tiket_ditolak_ke_route_user(): void
    {
        $rows = array_fill(0, 6, $this->userRow());

        $this->actingAs($this->user, 'web')
            ->post(route('tiket.bulk.store'), ['tickets' => $rows])
            ->assertRedirect(route('tiket.bulk.create'))
            ->assertSessionHasErrors('tickets');

        $this->assertSame(0, Ticket::count());
    }

    public function test_user_tidak_bisa_menyuntikkan_prioritas_urgen_pada_bulk_store(): void
    {
        $this->actingAs($this->user, 'web')
            ->post(route('tiket.bulk.store'), ['tickets' => [
                $this->userRow(['priority' => 'Urgen']),
            ]])
            ->assertRedirect(route('tiket.riwayat'));

        $ticket = Ticket::firstOrFail();
        $this->assertSame($this->user->id, $ticket->user_id);
        $this->assertNull($ticket->priority);
    }

    public function test_user_bulk_rollback_atomik_saat_booking_bentrok_dan_hapus_file(): void
    {
        $vehicle = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Peminjaman Mobil User',
            'deskripsi' => 'Kendaraan',
            'aktif' => true,
            'is_monitored' => true,
            'monitor_kategori' => 'Kendaraan',
        ]);

        $asset = FormField::create([
            'sub_unit_id' => $vehicle->id,
            'label' => 'Kendaraan',
            'tipe_field' => 'dropdown',
            'opsi' => ['APV'],
            'wajib' => true,
            'urutan' => 1,
        ]);
        $date = FormField::create([
            'sub_unit_id' => $vehicle->id,
            'label' => 'Tanggal Pinjam',
            'tipe_field' => 'tanggal',
            'wajib' => true,
            'urutan' => 2,
        ]);
        $start = FormField::create([
            'sub_unit_id' => $vehicle->id,
            'label' => 'Jam Mulai',
            'tipe_field' => 'waktu',
            'wajib' => true,
            'urutan' => 3,
        ]);
        $end = FormField::create([
            'sub_unit_id' => $vehicle->id,
            'label' => 'Jam Selesai',
            'tipe_field' => 'waktu',
            'wajib' => true,
            'urutan' => 4,
        ]);

        $vehicle->update([
            'monitor_asset_field_id' => $asset->id,
            'monitor_date_field_id' => $date->id,
            'monitor_start_field_id' => $start->id,
            'monitor_end_field_id' => $end->id,
        ]);

        $formData = json_encode([
            (string) $asset->id => 'APV',
            (string) $date->id => '2026-07-20',
            (string) $start->id => '10:00',
            (string) $end->id => '11:00',
        ]);

        $this->actingAs($this->user, 'web')
            ->post(route('tiket.bulk.store'), ['tickets' => [
                [
                    'unit_id' => $this->unit->id,
                    'sub_unit_id' => $vehicle->id,
                    'form_data' => $formData,
                    'attachment' => UploadedFile::fake()->image('user-booking.png', 10, 10),
                    'attachment_target' => 'general',
                ],
                [
                    'unit_id' => $this->unit->id,
                    'sub_unit_id' => $vehicle->id,
                    'form_data' => $formData,
                ],
            ]])
            ->assertRedirect(route('tiket.bulk.create'))
            ->assertSessionHasErrors([
                'tickets.1.form_data.'.$asset->id => 'Aset ini sudah dipesan pada jam tersebut.',
            ]);

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, RoomVehicleBooking::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_user_nonaktif_ditolak_mengakses_bulk_create_dan_store(): void
    {
        $inactive = User::factory()->create(['is_active' => false]);

        $this->actingAs($inactive, 'web')
            ->get(route('tiket.bulk.create'))
            ->assertRedirect(route('login'));

        $this->actingAs($inactive, 'web')
            ->post(route('tiket.bulk.store'), ['tickets' => [$this->userRow()]])
            ->assertRedirect(route('login'));

        $this->assertSame(0, Ticket::count());
    }
}
