<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\FormField;
use App\Models\RoomVehicleBooking;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MonitorTicketAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $other;
    private Admin $admin;
    private Ticket $ownTicket;
    private Ticket $otherTicket;
    private Ticket $adminTicket;
    private Ticket $ownTool;
    private Ticket $otherTool;
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir().'/haloapu-monitor-test-'.Str::uuid();
        config(['filesystems.disks.public.root' => $this->storagePath]);
        Storage::forgetDisk('public');

        foreach (['Admin', 'Operator', 'superadmin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'admin']);
        }

        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->admin = Admin::factory()->create(['username' => 'monitor-admin']);
        $this->admin->assignRole('Admin');
        $unit = Unit::create(['nama_unit' => 'Unit Monitor', 'aktif' => true]);
        $bookingService = SubUnit::create([
            'unit_id' => $unit->id,
            'nama_layanan' => 'Reservasi Ruangan',
            'aktif' => true,
        ]);
        $toolService = SubUnit::create([
            'unit_id' => $unit->id,
            'nama_layanan' => 'Peminjaman Alat',
            'aktif' => true,
            'wajib_kembali' => true,
        ]);
        $field = FormField::create([
            'sub_unit_id' => $toolService->id,
            'label' => 'Alat',
            'tipe_field' => 'dropdown',
            'opsi' => ['Proyektor A', 'Proyektor B'],
            'urutan' => 1,
        ]);

        $this->ownTicket = $this->makeTicket($unit, $bookingService, $this->owner);
        $this->otherTicket = $this->makeTicket($unit, $bookingService, $this->other);
        $this->adminTicket = Ticket::create([
            'created_by_admin_id' => $this->admin->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $bookingService->id,
            'form_data' => [],
            'status' => 'open',
        ]);

        foreach (['Ruang A' => $this->ownTicket, 'Ruang B' => $this->otherTicket, 'Ruang Admin' => $this->adminTicket] as $name => $ticket) {
            RoomVehicleBooking::create([
                'ticket_id' => $ticket->id,
                'tipe' => 'ruang',
                'nama_aset' => $name,
                'tanggal_mulai' => now()->addHour(),
                'tanggal_selesai' => now()->addHours(2),
                'status' => 'open',
            ]);
        }

        $this->ownTool = $this->makeTicket($unit, $toolService, $this->owner, [$field->id => 'Proyektor A']);
        $this->otherTool = $this->makeTicket($unit, $toolService, $this->other, [$field->id => 'Proyektor B']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storagePath);
        parent::tearDown();
    }

    private function makeTicket(Unit $unit, SubUnit $service, User $user, array $formData = []): Ticket
    {
        return Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $service->id,
            'form_data' => $formData,
            'status' => 'open',
        ]);
    }

    public function test_user_only_gets_links_to_owned_bookings_and_tools_even_with_admin_session(): void
    {
        $this->actingAs($this->admin, 'admin')->actingAs($this->owner, 'web');
        $response = $this->get(route('monitor'))->assertOk();
        $props = $response->original->getData()['page']['props'];
        $assets = collect($props['assets'])->keyBy('nama_aset');
        $calendar = collect($props['calendarData'])->flatMap(fn ($day) => $day['bookings'])->keyBy('nama_aset');
        $tools = collect($props['alatPinjam'])->keyBy('ticket_id');
        $catalog = collect($props['ketersediaanAlat'])->keyBy('nama_alat');

        $this->assertSame(route('tiket.show', $this->ownTicket), $assets['Ruang A']['ticket_url']);
        $this->assertNull($assets['Ruang B']['ticket_url']);
        $this->assertNull($assets['Ruang Admin']['ticket_url']);
        $this->assertSame($this->admin->name, $assets['Ruang Admin']['user']);
        $this->assertSame(route('tiket.show', $this->ownTicket), $calendar['Ruang A']['ticket_url']);
        $this->assertNull($calendar['Ruang B']['ticket_url']);
        $this->assertSame($this->admin->name, $calendar['Ruang Admin']['user']);
        $this->assertSame(route('tiket.show', $this->ownTool), $tools[$this->ownTool->id]['ticket_url']);
        $this->assertNull($tools[$this->otherTool->id]['ticket_url']);
        $this->assertSame(route('tiket.show', $this->ownTool), $catalog['Proyektor A']['ticket_url']);
        $this->assertNull($catalog['Proyektor B']['ticket_url']);
        $this->get(route('tiket.show', $this->ownTicket))->assertOk();
        $this->get(route('tiket.show', $this->otherTicket))->assertForbidden();
        $this->get(route('tiket.show', $this->otherTool))->assertForbidden();
    }

    public function test_admin_can_view_other_tickets_and_attachments_but_cannot_manage_them(): void
    {
        Storage::disk('public')->put('monitor/test.txt', 'lampiran');
        $attachment = TicketAttachment::create([
            'ticket_id' => $this->otherTicket->id,
            'file_path' => 'monitor/test.txt',
            'original_name' => 'test.txt',
            'mime_type' => 'text/plain',
            'file_size' => 8,
        ]);
        $this->actingAs($this->admin, 'admin');
        $response = $this->get(route('admin.monitor.index'))->assertOk();
        $props = $response->original->getData()['page']['props'];
        $assets = collect($props['assets'])->keyBy('nama_aset');
        $calendar = collect($props['calendarData'])->flatMap(fn ($day) => $day['bookings'])->keyBy('nama_aset');
        $tools = collect($props['alatPinjam'])->keyBy('ticket_id');
        $catalog = collect($props['ketersediaanAlat'])->keyBy('nama_alat');

        $this->assertSame(route('admin.tiket.show', $this->otherTicket), $assets['Ruang B']['ticket_url']);
        $this->assertSame(route('admin.tiket.show', $this->otherTicket), $calendar['Ruang B']['ticket_url']);
        $this->assertSame(route('admin.tiket.show', $this->otherTool), $tools[$this->otherTool->id]['ticket_url']);
        $this->assertSame(route('admin.tiket.show', $this->otherTool), $catalog['Proyektor B']['ticket_url']);
        $this->get(route('admin.tiket.show', $this->otherTicket))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Tiketing/Detail')->where('canManage', false)->where('canEditForm', false));
        $this->get(route('admin.tiket.download', $attachment))->assertOk();
        $this->get(route('admin.tiket.view', $attachment))->assertOk();
        $this->patch(route('admin.tiket.status', $this->otherTicket), ['status' => 'on_proses'])->assertForbidden();
        $this->patch(route('admin.tiket.priority', $this->otherTicket), ['priority' => 'Urgen'])->assertForbidden();
        $this->patch(route('admin.tiket.assign', $this->otherTicket), ['assigned_admin_id' => $this->admin->id])->assertForbidden();

        $operator = Admin::factory()->create();
        $operator->assignRole('Operator');
        $this->actingAs($operator, 'admin');
        $this->get(route('admin.tiket.download', $attachment))->assertForbidden();
        $this->get(route('admin.tiket.view', $attachment))->assertForbidden();
    }

    public function test_operator_keeps_unit_scoped_access_while_superadmin_sees_every_ticket(): void
    {
        $operator = Admin::factory()->create();
        $operator->assignRole('Operator');
        $this->actingAs($operator, 'admin');
        $props = $this->get(route('admin.monitor.index'))->assertOk()->original->getData()['page']['props'];
        $this->assertNull(collect($props['assets'])->firstWhere('nama_aset', 'Ruang B')['ticket_url']);
        $this->assertNull(collect($props['alatPinjam'])->firstWhere('ticket_id', $this->otherTool->id)['ticket_url']);
        $this->get(route('admin.tiket.show', $this->otherTicket))->assertForbidden();

        $superadmin = Admin::factory()->create();
        $superadmin->assignRole('superadmin');
        $this->actingAs($superadmin, 'admin');
        $props = $this->get(route('admin.monitor.index'))->assertOk()->original->getData()['page']['props'];
        $this->assertSame(route('admin.tiket.show', $this->otherTicket), collect($props['assets'])->firstWhere('nama_aset', 'Ruang B')['ticket_url']);
        $this->get(route('admin.tiket.show', $this->otherTicket))->assertOk();
    }
}
