<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TicketAssignedOperatorNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TicketAssignedBroadcastPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_broadcast_membawa_id_dan_url_untuk_handler_echo(): void
    {
        $operator = Admin::factory()->create(['username' => 'operator1']);
        $user = User::factory()->create();
        $unit = Unit::create(['nama_unit' => 'Umum', 'deskripsi' => 'Unit', 'aktif' => true]);
        $subUnit = SubUnit::create([
            'unit_id' => $unit->id,
            'nama_layanan' => 'Kerusakan Umum',
            'deskripsi' => 'Layanan umum',
            'aktif' => true,
        ]);
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'sub_unit_id' => $subUnit->id,
            'unit_id' => $unit->id,
            'form_data' => ['1' => 'AC bocor'],
            'status' => 'open',
            'priority' => 'normal',
            'source' => 'web',
        ]);

        $captured = null;
        Event::listen(BroadcastNotificationCreated::class, function ($event) use (&$captured) {
            $captured = $event->broadcastWith();
        });

        $operator->notify(new TicketAssignedOperatorNotification($ticket->load('subUnit')));

        $this->assertNotNull($captured, 'Event broadcast tidak dipancarkan.');
        $this->assertSame('Penugasan Tiket Baru', $captured['title']);
        $this->assertSame($ticket->id, $captured['ticket_id']);
        $this->assertStringContainsString('/admin/tiket/'.$ticket->id, $captured['url']);
        $this->assertNotEmpty($captured['id'], 'id dipakai sebagai tag dedupe notifikasi browser.');
    }
}
