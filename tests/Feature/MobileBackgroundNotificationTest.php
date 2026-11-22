<?php

namespace Tests\Feature;

use App\Channels\FcmChannel;
use App\Models\Admin;
use App\Models\Conversation;
use App\Models\SubUnit;
use App\Models\SystemConfig;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MobileBackgroundNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        FcmService::resetLastSendStatus();
    }

    private function generateRsaPrivateKey(): string
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $windowsOpensslCnf = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        if (is_file($windowsOpensslCnf)) {
            $config['config'] = $windowsOpensslCnf;
        }

        $key = openssl_pkey_new($config);
        $this->assertNotFalse($key);

        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem, null, $config));

        return $pem;
    }

    private function adminWithConfigPermission(): Admin
    {
        Permission::firstOrCreate(['name' => 'akses-konfigurasi', 'guard_name' => 'admin']);

        $admin = Admin::factory()->create();
        $admin->givePermissionTo('akses-konfigurasi');

        return $admin;
    }

    public function test_firebase_service_account_upload_validation_authorization_and_delete(): void
    {
        $this->withoutMiddleware([PreventRequestForgery::class]);
        Storage::fake('local');

        $unprivilegedAdmin = Admin::factory()->create();
        $configAdmin = $this->adminWithConfigPermission();

        $validJson = json_encode([
            'type' => 'service_account',
            'project_id' => 'haloapu-prod-123',
            'private_key_id' => 'key-abc',
            'private_key' => $this->generateRsaPrivateKey(),
            'client_email' => 'firebase-adminsdk-xyz@haloapu-prod-123.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);

        // Unprivileged admin gets 403
        $this->actingAs($unprivilegedAdmin, 'admin')
            ->post('/admin/konfigurasi/upload-firebase', [
                'firebase_credentials' => UploadedFile::fake()->createWithContent('sa.json', (string) $validJson),
            ])
            ->assertStatus(403);

        // Invalid JSON / wrong type / invalid private key rejected
        $this->actingAs($configAdmin, 'admin')
            ->post('/admin/konfigurasi/upload-firebase', [
                'firebase_credentials' => UploadedFile::fake()->createWithContent('bad.json', 'not-json'),
            ])
            ->assertSessionHasErrors('firebase_credentials');

        $this->actingAs($configAdmin, 'admin')
            ->post('/admin/konfigurasi/upload-firebase', [
                'firebase_credentials' => UploadedFile::fake()->createWithContent('bad-key.json', (string) json_encode([
                    'type' => 'service_account',
                    'project_id' => 'haloapu-prod-123',
                    'client_email' => 'bot@haloapu-prod-123.iam.gserviceaccount.com',
                    'private_key' => 'INVALID_PEM',
                ])),
            ])
            ->assertSessionHasErrors('firebase_credentials');

        Storage::disk('local')->assertMissing('firebase/service-account.json');

        // Valid upload stores in private local disk (storage/app/private/firebase/service-account.json)
        $this->actingAs($configAdmin, 'admin')
            ->post('/admin/konfigurasi/upload-firebase', [
                'firebase_credentials' => UploadedFile::fake()->createWithContent('sa.json', (string) $validJson),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Storage::disk('local')->assertExists('firebase/service-account.json');
        $this->assertSame('firebase/service-account.json', SystemConfig::getValue('fcm_service_account_path'));

        $status = FcmService::getDiagnosticStatus();
        $this->assertTrue($status['enabled']);
        $this->assertTrue($status['configured']);
        $this->assertSame('http_v1_panel', $status['mode']);
        $this->assertSame('haloapu-prod-123', $status['project_id']);
        $this->assertSame('fireba***@haloapu-prod-123.iam.gserviceaccount.com', $status['client_email_masked']);

        // Test FCM push via admin endpoint uses uploaded HTTP v1 credentials
        $configAdmin->fcm_token = 'admin-device-fcm-token';
        $configAdmin->save();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'panel-oauth-token',
                'expires_in' => 3600,
            ], 200),
            'https://fcm.googleapis.com/v1/projects/haloapu-prod-123/messages:send' => Http::response([
                'name' => 'projects/haloapu-prod-123/messages/1',
            ], 200),
        ]);

        $this->actingAs($configAdmin, 'admin')
            ->post('/admin/konfigurasi/test-fcm')
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://fcm.googleapis.com/v1/projects/haloapu-prod-123/messages:send'
                && ($request['message']['token'] ?? null) === 'admin-device-fcm-token'
                && ! empty($request['message']['data']['notification_id'])
                && ! empty($request['message']['android']['notification']['tag']);
        });

        // Delete credentials removes private file & config keys
        $this->actingAs($configAdmin, 'admin')
            ->delete('/admin/konfigurasi/firebase-credentials')
            ->assertRedirect()
            ->assertSessionHas('success');

        Storage::disk('local')->assertMissing('firebase/service-account.json');
        $this->assertNull(SystemConfig::getValue('fcm_service_account_path'));
        $this->assertSame('unconfigured', FcmService::getDiagnosticStatus()['mode']);
    }

    public function test_fcm_channel_injects_notification_id_and_supports_judul_pesan_fallback(): void
    {
        config([
            'services.fcm.key' => 'legacy-test-key',
            'services.fcm.service_account_json' => null,
        ]);

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response(['success' => 1], 200),
        ]);

        $user = User::factory()->create();
        $user->fcm_token = 'user-token-123';
        $user->save();

        $notification = new class extends Notification
        {
            public function via(object $notifiable): array
            {
                return ['database', FcmChannel::class];
            }

            public function toArray(object $notifiable): array
            {
                return [
                    'ticket_id' => 77,
                    'judul' => 'Pengingat Jadwal',
                    'pesan' => 'Booking ruang dimulai 10 menit lagi.',
                ];
            }
        };

        $user->notify($notification);

        $stored = $user->notifications()->firstOrFail();

        Http::assertSent(function ($request) use ($stored) {
            return $request->url() === 'https://fcm.googleapis.com/fcm/send'
                && ($request['notification']['title'] ?? null) === 'Pengingat Jadwal'
                && ($request['notification']['body'] ?? null) === 'Booking ruang dimulai 10 menit lagi.'
                && ($request['data']['notification_id'] ?? null) === (string) $stored->id
                && ($request['data']['id'] ?? null) === (string) $stored->id;
        });
    }

    public function test_chat_message_and_admin_ticket_reply_send_database_and_fcm_notifications(): void
    {
        config([
            'services.fcm.key' => 'legacy-test-key',
            'services.fcm.service_account_json' => null,
        ]);

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response(['success' => 1], 200),
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->fcm_token = 'requester-fcm-token';
        $user->save();

        $admin = Admin::factory()->create(['name' => 'Operator Budi']);
        $admin->fcm_token = 'operator-fcm-token';
        $admin->save();

        $unit = Unit::create(['nama_unit' => 'IT', 'aktif' => true]);
        $subUnit = SubUnit::create(['unit_id' => $unit->id, 'nama_layanan' => 'Jaringan', 'aktif' => true]);

        $ticket = Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $subUnit->id,
            'assigned_admin_id' => $admin->id,
            'form_data' => ['1' => 'Wi-Fi mati'],
            'status' => 'on_proses',
        ]);

        // 1. Admin replies via API /api/tickets/{id}/reply -> user receives database + FCM notification
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/tickets/{$ticket->id}/reply", [
                'catatan' => 'Kami sedang menuju lokasi.',
            ])
            ->assertStatus(201);

        $this->assertSame(
            1,
            $user->notifications()->where('type', \App\Notifications\TicketCommentPushNotification::class)->count()
        );
        $this->assertSame(0, $admin->notifications()->count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => ($req['to'] ?? null) === 'requester-fcm-token'
            && ($req['data']['type'] ?? null) === 'ticket_comment'
            && ! empty($req['data']['notification_id']));

        // 2. User sends chat message in ticket conversation -> assigned admin receives database + FCM notification
        $conversation = Conversation::where('type', 'ticket')
            ->where('ticket_id', $ticket->id)
            ->firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'body' => 'Terima kasih pak, saya tunggu di ruang rapat.',
            ])
            ->assertStatus(201);

        $this->assertSame(
            1,
            $admin->notifications()->where('type', \App\Notifications\TicketCommentPushNotification::class)->count()
        );
        $this->assertSame(
            1,
            $user->notifications()->where('type', \App\Notifications\TicketCommentPushNotification::class)->count()
        ); // unchanged (sender not notified)

        // 3. Direct chat message without a ticket -> recipient receives DirectChatMessageNotification
        $directConversation = Conversation::create([
            'type' => 'user_admin_direct',
            'user_id' => $user->id,
            'admin_one_id' => $admin->id,
            'last_message_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/conversations/{$directConversation->id}/messages", [
                'body' => 'Halo, apakah kendala sudah aman?',
            ])
            ->assertStatus(201);

        $this->assertSame(
            1,
            $user->notifications()->where('type', \App\Notifications\DirectChatMessageNotification::class)->count()
        );
        $latestUserNotif = $user->notifications()
            ->where('type', \App\Notifications\DirectChatMessageNotification::class)
            ->latest()
            ->firstOrFail();
        $this->assertSame('chat_message', $latestUserNotif->data['type']);
    }

    public function test_notification_api_index_reads_judul_and_pesan_fallbacks(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => \App\Notifications\CsatReminderNotification::class,
            'data' => [
                'ticket_id' => 55,
                'judul' => 'Berikan Rating Tiket Kamu',
                'pesan' => 'Tiket #55 sudah selesai, mohon isi rating.',
            ],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Berikan Rating Tiket Kamu')
            ->assertJsonPath('data.0.body', 'Tiket #55 sudah selesai, mohon isi rating.')
            ->assertJsonPath('data.0.ticketId', '55');
    }
}
