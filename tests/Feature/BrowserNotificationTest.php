<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use App\Notifications\BrowserNotification;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BrowserNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_null_menyimpan_notifikasi_ke_database_tanpa_error(): void
    {
        config(['broadcasting.default' => 'null']);

        $user = User::factory()->create(['notify_inapp' => true]);

        $user->notify(new BrowserNotification(
            'Balasan Baru Tiket #TKT-1',
            'Admin membalas tiket Anda.',
            '/tiket/1'
        ));

        $stored = $user->notifications()->first();
        $this->assertNotNull($stored);
        $this->assertSame('Balasan Baru Tiket #TKT-1', $stored->data['title']);
        $this->assertSame('Admin membalas tiket Anda.', $stored->data['message']);
        $this->assertSame('/tiket/1', $stored->data['url']);
        $this->assertSame('/tiket/1', $stored->data['aksi_url']);
    }

    public function test_driver_null_menghormati_preferensi_notify_inapp_off(): void
    {
        config(['broadcasting.default' => 'null']);

        $admin = Admin::factory()->create(['notify_inapp' => false]);

        $admin->notify(new BrowserNotification(
            'Pengingat Booking',
            'Booking hampir dimulai.',
            '/admin/tiket/5'
        ));

        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_driver_reverb_mengirim_lewat_channel_broadcast(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Broadcast::extend('reverb', fn () => new NullBroadcaster);
        Broadcast::purge('reverb');

        $user = User::factory()->create();
        $captured = null;
        Event::listen(BroadcastNotificationCreated::class, function ($event) use (&$captured) {
            $captured = $event->broadcastWith();
        });

        $user->notify(new BrowserNotification('Judul Uji', 'Pesan Uji', '/tiket/9'));

        $this->assertNotNull($captured);
        $this->assertSame('Judul Uji', $captured['title']);
        $this->assertSame('Pesan Uji', $captured['message']);
        $this->assertSame('/tiket/9', $captured['url']);
        $this->assertSame(0, $user->notifications()->count());
    }
}
