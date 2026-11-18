<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketLog;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStatusNoteOptionalTest extends TestCase
{
    use RefreshDatabase;

    private function makeTicket(Admin $admin): Ticket
    {
        $user = User::factory()->create();
        $unit = Unit::create(['nama_unit' => 'Umum', 'deskripsi' => 'Unit', 'aktif' => true]);
        $subUnit = SubUnit::create([
            'unit_id' => $unit->id,
            'nama_layanan' => 'Kerusakan Umum',
            'deskripsi' => 'Layanan umum',
            'aktif' => true,
        ]);

        return Ticket::create([
            'user_id' => $user->id,
            'sub_unit_id' => $subUnit->id,
            'unit_id' => $unit->id,
            'form_data' => ['1' => 'AC bocor'],
            'status' => 'open',
            'priority' => 'normal',
            'source' => 'web',
            'assigned_admin_id' => $admin->id,
        ]);
    }

    public function test_ubah_status_tanpa_catatan_tetap_berhasil(): void
    {
        $admin = Admin::factory()->create(['username' => 'operator1']);
        $ticket = $this->makeTicket($admin);

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.tiket.status', $ticket->id), ['status' => 'on_proses'])
            ->assertSessionHasNoErrors();

        $this->assertSame('on_proses', $ticket->fresh()->status);
        $log = TicketLog::where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $this->assertSame('Status tiket diubah dari open menjadi on_proses', $log->catatan);
    }

    public function test_catatan_tetap_tersimpan_bila_diisi(): void
    {
        $admin = Admin::factory()->create(['username' => 'operator1']);
        $ticket = $this->makeTicket($admin);

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.tiket.status', $ticket->id), [
                'status' => 'on_proses',
                'catatan' => 'Sedang dicek teknisi',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Sedang dicek teknisi', TicketLog::where('ticket_id', $ticket->id)->latest('id')->firstOrFail()->catatan);
    }
}
