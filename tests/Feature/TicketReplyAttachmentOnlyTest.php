<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketLog;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketReplyAttachmentOnlyTest extends TestCase
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

    public function test_admin_bisa_kirim_lampiran_tanpa_catatan(): void
    {
        Storage::fake('public');
        $admin = Admin::factory()->create(['username' => 'operator1']);
        $ticket = $this->makeTicket($admin);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tiket.reply', $ticket->id), [
                'general_attachments' => [UploadedFile::fake()->image('swan.png')],
            ])
            ->assertSessionHasNoErrors();

        $log = TicketLog::where('ticket_id', $ticket->id)->where('aksi', 'balasan')->firstOrFail();
        $this->assertSame('[Lampiran Tiket]', $log->catatan);
        $this->assertCount(1, $log->attachments);
    }

    public function test_admin_tanpa_catatan_dan_tanpa_lampiran_ditolak(): void
    {
        $admin = Admin::factory()->create(['username' => 'operator1']);
        $ticket = $this->makeTicket($admin);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tiket.reply', $ticket->id), [])
            ->assertSessionHasErrors('catatan');

        $this->assertSame(0, TicketLog::where('ticket_id', $ticket->id)->where('aksi', 'balasan')->count());
    }
}
