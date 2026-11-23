<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TicketAssignedOperatorNotification;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketAssignedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function enableRealtimeWithoutSocket(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Broadcast::extend('reverb', fn () => new NullBroadcaster);
        Broadcast::purge('reverb');
    }

    private function makeTicket(): Ticket
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
        ]);
    }

    public function test_penugasan_web_mengirim_notifikasi_browser(): void
    {
        $this->enableRealtimeWithoutSocket();
        Notification::fake();

        $superAdmin = Admin::factory()->create(['username' => 'superadmin']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'admin']);
        $superAdmin->assignRole('Super Admin');

        $operator = Admin::factory()->create(['username' => 'operator1']);
        $ticket = $this->makeTicket();
        $operator->units()->attach($ticket->unit_id);

        $this->actingAs($superAdmin, 'admin')
            ->patch(route('admin.tiket.assign', $ticket->id), ['assigned_admin_id' => $operator->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($operator->id, (int) $ticket->fresh()->assigned_admin_id);

        Notification::assertSentTo($operator, TicketAssignedOperatorNotification::class, function ($notification, $channels) {
            return in_array('broadcast', $channels, true)
                && in_array('database', $channels, true);
        });
    }

    public function test_payload_broadcast_punya_judul_pesan_dan_url(): void
    {
        $operator = Admin::factory()->create(['username' => 'operator1', 'notify_browser' => true]);
        $ticket = $this->makeTicket();

        $payload = (new TicketAssignedOperatorNotification($ticket->load('subUnit')))
            ->toBroadcast($operator)
            ->data;

        $this->assertSame('Penugasan Tiket Baru', $payload['title']);
        $this->assertStringContainsString('Kerusakan Umum', $payload['message']);
        $this->assertStringContainsString('/admin/tiket/'.$ticket->id, $payload['url']);
        $this->assertSame('ticket_assigned', $payload['type']);
    }

    public function test_notify_browser_off_tidak_memblokir_channel_broadcast(): void
    {
        $this->enableRealtimeWithoutSocket();

        $operator = Admin::factory()->create(['username' => 'operator1', 'notify_browser' => false]);
        $ticket = $this->makeTicket();

        $channels = (new TicketAssignedOperatorNotification($ticket))->via($operator);

        // Data + realtime tetap jalan; yang mati hanya WebPush (notifikasi OS).
        $this->assertContains('broadcast', $channels);
        $this->assertContains('database', $channels);
        $this->assertNotContains(\NotificationChannels\WebPush\WebPushChannel::class, $channels);
    }

    public function test_broadcast_driver_null_menjatuhkan_channel_broadcast(): void
    {
        config(['broadcasting.default' => 'null']);

        $operator = Admin::factory()->create(['username' => 'operator1']);
        $ticket = $this->makeTicket();

        $channels = (new TicketAssignedOperatorNotification($ticket))->via($operator);

        $this->assertNotContains('broadcast', $channels);
        $this->assertContains('database', $channels);
    }
}
