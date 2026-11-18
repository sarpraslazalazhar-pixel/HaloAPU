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

class DbgReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_dbg(): void
    {
        Storage::fake('public');
        $out = '';
        $admin = Admin::factory()->create(['username' => 'operator1']);
        $user = User::factory()->create();
        $unit = Unit::create(['nama_unit' => 'Umum', 'deskripsi' => 'Unit', 'aktif' => true]);
        $subUnit = SubUnit::create(['unit_id' => $unit->id, 'nama_layanan' => 'Kerusakan Umum', 'deskripsi' => 'Layanan umum', 'aktif' => true]);
        $ticket = Ticket::create(['user_id' => $user->id, 'sub_unit_id' => $subUnit->id, 'unit_id' => $unit->id, 'form_data' => ['1' => 'AC bocor'], 'status' => 'open', 'priority' => 'normal', 'source' => 'web', 'assigned_admin_id' => $admin->id]);

        $this->actingAs($admin, 'admin');
        $r = $this->post(route('admin.tiket.reply', $ticket->id), ['general_attachments' => [UploadedFile::fake()->image('swan.png')]]);
        $out .= "R1 status={$r->status()} loc=".($r->headers->get('Location') ?? '-')." errs=".json_encode(session("errors") ?? [])."\n";
        $out .= 'logs='.TicketLog::where('ticket_id', $ticket->id)->count()."\n";

        $r2 = $this->post(route('admin.tiket.reply', $ticket->id), []);
        $out .= "R2 status={$r2->status()} loc=".($r2->headers->get('Location') ?? '-')." errs=".json_encode(session("errors") ?? [])."\n";

        file_put_contents(base_path('dbg.out'), $out);
        $this->assertTrue(true);
    }
}
