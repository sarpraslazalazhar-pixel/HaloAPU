<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AppRelease;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AppReleaseTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_SHA256 = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function adminWithConfigPermission(): Admin
    {
        Permission::firstOrCreate(['name' => 'akses-konfigurasi', 'guard_name' => 'admin']);

        $admin = Admin::factory()->create();
        $admin->givePermissionTo('akses-konfigurasi');

        return $admin;
    }

    public function test_public_app_version_returns_null_when_no_active_release_and_is_always_non_blocking(): void
    {
        $this->getJson('/api/app-version')
            ->assertOk()
            ->assertJson(['data' => null]);

        AppRelease::create([
            'platform' => 'android',
            'channel' => 'production',
            'version_name' => '1.0.1',
            'build_number' => 11,
            'min_supported_build_number' => 10,
            'force_update' => false,
            'title' => 'Inactive Draft',
            'download_url' => 'https://github.com/org/repo/releases/download/v1.0.1/app.apk',
            'sha256' => self::VALID_SHA256,
            'is_active' => false,
        ]);

        // Inactive release must not be returned
        $this->getJson('/api/app-version?platform=android&channel=production')
            ->assertOk()
            ->assertJson(['data' => null]);

        // Even if an existing row has force_update=true or min_supported_build_number>0,
        // the public API always returns force_update=false and min_supported_build_number=0.
        $prod = AppRelease::create([
            'platform' => 'android',
            'channel' => 'production',
            'version_name' => '1.0.2',
            'build_number' => 12,
            'min_supported_build_number' => 10,
            'force_update' => true,
            'title' => 'Update Production',
            'release_notes' => '- Fix session',
            'download_url' => 'https://github.com/org/repo/releases/download/v1.0.2/prod.apk',
            'sha256' => self::VALID_SHA256,
            'file_size_bytes' => 42000000,
            'is_active' => false,
        ]);
        $prod->activate();

        $staging = AppRelease::create([
            'platform' => 'android',
            'channel' => 'staging',
            'version_name' => '1.0.3-rc1',
            'build_number' => 15,
            'min_supported_build_number' => 0,
            'force_update' => false,
            'title' => 'Update Staging',
            'download_url' => 'https://objects.githubusercontent.com/org/repo/staging.apk',
            'sha256' => self::VALID_SHA256,
            'is_active' => false,
        ]);
        $staging->activate();

        $this->getJson('/api/app-version?platform=android&channel=production')
            ->assertOk()
            ->assertJsonPath('data.version_name', '1.0.2')
            ->assertJsonPath('data.build_number', 12)
            ->assertJsonPath('data.force_update', false)
            ->assertJsonPath('data.min_supported_build_number', 0)
            ->assertJsonPath('data.channel', 'production');

        $this->getJson('/api/app-version?platform=android&channel=staging')
            ->assertOk()
            ->assertJsonPath('data.version_name', '1.0.3-rc1')
            ->assertJsonPath('data.build_number', 15)
            ->assertJsonPath('data.force_update', false)
            ->assertJsonPath('data.min_supported_build_number', 0)
            ->assertJsonPath('data.channel', 'staging');

        $this->getJson('/api/app-version?platform=ios&channel=production')
            ->assertOk()
            ->assertJson(['data' => null]);

        $this->getJson('/api/app-version?platform=windows')
            ->assertStatus(422);
    }

    public function test_security_validations_reject_unsafe_urls_and_invalid_sha256(): void
    {
        $this->withoutMiddleware([PreventRequestForgery::class]);
        $admin = $this->adminWithConfigPermission();

        $basePayload = [
            'platform' => 'android',
            'channel' => 'production',
            'version_name' => '1.0.2',
            'build_number' => 12,
            'download_url' => 'https://github.com/org/repo/releases/download/v1.0.2/app.apk',
            'sha256' => self::VALID_SHA256,
        ];

        // Reject http://
        $this->actingAs($admin, 'admin')
            ->post('/admin/app-releases', array_merge($basePayload, [
                'download_url' => 'http://github.com/org/repo/releases/download/v1.0.2/app.apk',
            ]))
            ->assertSessionHasErrors('download_url');

        // Reject credentials in URL
        $this->actingAs($admin, 'admin')
            ->post('/admin/app-releases', array_merge($basePayload, [
                'download_url' => 'https://user:pass@github.com/org/repo/releases/download/v1.0.2/app.apk',
            ]))
            ->assertSessionHasErrors('download_url');

        // Reject untrusted host
        $this->actingAs($admin, 'admin')
            ->post('/admin/app-releases', array_merge($basePayload, [
                'download_url' => 'https://evil.com/app.apk',
            ]))
            ->assertSessionHasErrors('download_url');

        // Reject host spoofing suffix
        $this->actingAs($admin, 'admin')
            ->post('/admin/app-releases', array_merge($basePayload, [
                'download_url' => 'https://githubusercontent.com.evil.com/app.apk',
            ]))
            ->assertSessionHasErrors('download_url');

        // Reject invalid SHA-256
        $this->actingAs($admin, 'admin')
            ->post('/admin/app-releases', array_merge($basePayload, [
                'sha256' => 'not-a-valid-sha256',
            ]))
            ->assertSessionHasErrors('sha256');

        $this->assertDatabaseCount('app_releases', 0);
    }

    public function test_internal_webhook_enforces_secret_normalizes_optional_update_and_upserts(): void
    {
        $payload = [
            'platform' => 'android',
            'channel' => 'production',
            'version_name' => '1.0.5',
            'build_number' => 25,
            'min_supported_build_number' => 20,
            'force_update' => true,
            'title' => 'Rilis v1.0.5',
            'release_notes' => 'Perbaikan bug',
            'download_url' => 'https://github.com/org/repo/releases/download/v1.0.5/app.apk',
            'sha256' => strtoupper(self::VALID_SHA256),
            'file_size_bytes' => 35000000,
            'is_active' => false,
        ];

        // 503 when webhook secret is empty
        config(['services.app_release.webhook_secret' => '']);
        $this->postJson('/api/internal/app-releases', $payload)
            ->assertStatus(503);

        // Even with empty X-Release-Token header when secret is empty -> 503
        $this->withHeader('X-Release-Token', '')
            ->postJson('/api/internal/app-releases', $payload)
            ->assertStatus(503);

        // 401 when token is missing or invalid
        config(['services.app_release.webhook_secret' => 'super-secret-release-token']);
        $this->postJson('/api/internal/app-releases', $payload)
            ->assertStatus(401);

        $this->withHeader('X-Release-Token', 'wrong-token')
            ->postJson('/api/internal/app-releases', $payload)
            ->assertStatus(401);

        // 201 on initial creation (force_update & min_supported_build_number normalized to non-blocking)
        $this->withHeader('X-Release-Token', 'super-secret-release-token')
            ->postJson('/api/internal/app-releases', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.sha256', self::VALID_SHA256)
            ->assertJsonPath('data.build_number', 25)
            ->assertJsonPath('data.force_update', false)
            ->assertJsonPath('data.min_supported_build_number', 0);

        // Still inactive, so public endpoint returns null
        $this->getJson('/api/app-version')->assertOk()->assertJson(['data' => null]);

        // 200 on idempotent upsert and activates when is_active=true
        $this->withHeader('X-Release-Token', 'super-secret-release-token')
            ->postJson('/api/internal/app-releases', array_merge($payload, [
                'release_notes' => 'Catatan diperbarui',
                'is_active' => true,
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.release_notes', 'Catatan diperbarui')
            ->assertJsonPath('data.force_update', false)
            ->assertJsonPath('data.min_supported_build_number', 0);

        $this->assertDatabaseCount('app_releases', 1);
        $this->getJson('/api/app-version')
            ->assertOk()
            ->assertJsonPath('data.build_number', 25)
            ->assertJsonPath('data.release_notes', 'Catatan diperbarui')
            ->assertJsonPath('data.force_update', false);
    }

    public function test_admin_authorization_lifecycle_and_fcm_notify(): void
    {
        $this->withoutMiddleware([PreventRequestForgery::class]);

        $webUser = User::factory()->create();
        $unprivilegedAdmin = Admin::factory()->create();
        $configAdmin = $this->adminWithConfigPermission();

        $storePayload = [
            'platform' => 'android',
            'channel' => 'production',
            'version_name' => '1.0.2',
            'build_number' => 12,
            'min_supported_build_number' => 10,
            'force_update' => true,
            'title' => 'Update v1.0.2',
            'release_notes' => 'Perbaikan performa',
            'download_url' => 'https://github.com/org/repo/releases/download/v1.0.2/app.apk',
            'sha256' => self::VALID_SHA256,
            'file_size_bytes' => 40000000,
            'is_active' => true,
        ];

        // Guest & non-admin user redirected; admin without permission gets 403
        $this->post('/admin/app-releases', $storePayload)->assertRedirect(route('login'));
        $this->actingAs($webUser, 'web')->post('/admin/app-releases', $storePayload)->assertRedirect(route('login'));
        $this->actingAs($unprivilegedAdmin, 'admin')->post('/admin/app-releases', $storePayload)->assertStatus(403);

        // Authorized admin creates active v1.0.2 (Build 12); force_update is normalized to false
        $this->actingAs($configAdmin, 'admin')
            ->post('/admin/app-releases', $storePayload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $release12 = AppRelease::where('build_number', 12)->firstOrFail();
        $this->assertTrue($release12->is_active);
        $this->assertFalse($release12->force_update);
        $this->assertSame(0, $release12->min_supported_build_number);
        $this->getJson('/api/app-version')
            ->assertOk()
            ->assertJsonPath('data.build_number', 12)
            ->assertJsonPath('data.force_update', false);

        // Create inactive v1.0.3 (Build 13)
        $this->actingAs($configAdmin, 'admin')
            ->post('/admin/app-releases', array_merge($storePayload, [
                'version_name' => '1.0.3',
                'build_number' => 13,
                'force_update' => true,
                'is_active' => false,
            ]))
            ->assertRedirect();

        $release13 = AppRelease::where('build_number', 13)->firstOrFail();
        $this->assertFalse($release13->is_active);
        $this->assertFalse($release13->force_update);

        // Notify rejected on inactive release
        Http::fake();
        $this->actingAs($configAdmin, 'admin')
            ->post("/admin/app-releases/{$release13->id}/notify", [
                'target' => 'all',
                'notification_title' => 'Test',
                'notification_body' => 'Body',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
        Http::assertNothingSent();

        // Activate Build 13 -> deactivates Build 12 and updates cached public endpoint
        $this->actingAs($configAdmin, 'admin')
            ->post("/admin/app-releases/{$release13->id}/activate")
            ->assertRedirect();

        $this->assertFalse($release12->fresh()->is_active);
        $this->assertTrue($release13->fresh()->is_active);
        $this->getJson('/api/app-version')
            ->assertOk()
            ->assertJsonPath('data.build_number', 13)
            ->assertJsonPath('data.force_update', false)
            ->assertJsonPath('data.min_supported_build_number', 0);

        // Active release cannot be deleted
        $this->actingAs($configAdmin, 'admin')
            ->delete("/admin/app-releases/{$release13->id}")
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertDatabaseHas('app_releases', ['id' => $release13->id]);

        // Broadcast FCM notification to active users and admins (always force_update='0')
        config(['services.fcm.key' => 'test-fcm-server-key']);
        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response(['success' => 1], 200),
        ]);

        $activeUserWithToken = User::factory()->create(['is_active' => true]);
        $activeUserWithToken->fcm_token = 'user-fcm-token-1';
        $activeUserWithToken->save();

        $inactiveUserWithToken = User::factory()->create(['is_active' => false]);
        $inactiveUserWithToken->fcm_token = 'inactive-user-fcm-token';
        $inactiveUserWithToken->save();

        $configAdmin->fcm_token = 'admin-fcm-token-1';
        $configAdmin->save();

        $this->actingAs($configAdmin, 'admin')
            ->post("/admin/app-releases/{$release13->id}/notify", [
                'target' => 'all',
                'notification_title' => 'Update Halo APU v1.0.3',
                'notification_body' => 'Versi baru tersedia, silakan perbarui saat siap.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // Exactly 2 FCM requests sent (active user + admin; inactive user & tokenless accounts skipped for FCM)
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://fcm.googleapis.com/fcm/send'
                && ($request['data']['type'] ?? null) === 'app_update'
                && ($request['data']['channel'] ?? null) === 'production'
                && ($request['data']['version_name'] ?? null) === '1.0.3'
                && ($request['data']['build_number'] ?? null) === '13'
                && ($request['data']['force_update'] ?? null) === '0';
        });

        // All 4 active accounts (2 users + 2 admins, including tokenless ones) receive database notifications
        $release13->refresh();
        $this->assertNotNull($release13->last_notified_at);
        $this->assertSame(4, $release13->last_notified_count);
        $this->assertSame('all', $release13->last_notified_target);

        $this->assertSame(1, $webUser->notifications()->count());
        $this->assertSame(1, $activeUserWithToken->notifications()->count());
        $this->assertSame(0, $inactiveUserWithToken->notifications()->count());
        $this->assertSame(1, $configAdmin->notifications()->count());
        $this->assertSame(1, $unprivilegedAdmin->notifications()->count());

        // Mobile API /api/notifications and /api/notifications/unread-count expose app_update cleanly
        $this->actingAs($webUser, 'sanctum')
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->actingAs($webUser, 'sanctum')
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'app_update')
            ->assertJsonPath('data.0.title', 'Update Halo APU v1.0.3')
            ->assertJsonPath('data.0.channel', 'production')
            ->assertJsonPath('data.0.version_name', '1.0.3')
            ->assertJsonPath('data.0.build_number', '13')
            ->assertJsonPath('data.0.data.type', 'app_update');

        // Deactivate Build 13 -> public endpoint returns null, and Build 13 can now be deleted
        $this->actingAs($configAdmin, 'admin')
            ->post("/admin/app-releases/{$release13->id}/deactivate")
            ->assertRedirect();

        $this->getJson('/api/app-version')->assertOk()->assertJson(['data' => null]);

        $this->actingAs($configAdmin, 'admin')
            ->delete("/admin/app-releases/{$release13->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('app_releases', ['id' => $release13->id]);
    }

    public function test_fcm_service_http_v1_exchanges_jwt_and_caches_access_token(): void
    {
        $opensslConfig = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $windowsOpensslCnf = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        if (is_file($windowsOpensslCnf)) {
            $opensslConfig['config'] = $windowsOpensslCnf;
        }

        $keyResource = openssl_pkey_new($opensslConfig);
        $this->assertNotFalse($keyResource);

        $privateKeyPem = '';
        $this->assertTrue(openssl_pkey_export($keyResource, $privateKeyPem, null, $opensslConfig));

        $tempCredsPath = tempnam(sys_get_temp_dir(), 'fcm_test_');
        $this->assertNotFalse($tempCredsPath);

        try {
            file_put_contents($tempCredsPath, json_encode([
                'type' => 'service_account',
                'project_id' => 'halo-apu-test',
                'private_key_id' => 'key-1',
                'private_key' => $privateKeyPem,
                'client_email' => 'firebase-adminsdk@halo-apu-test.iam.gserviceaccount.com',
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ]));

            config([
                'services.fcm.service_account_json' => $tempCredsPath,
                'services.fcm.project_id' => 'halo-apu-test',
                'services.fcm.key' => null,
            ]);

            Http::fake([
                'https://oauth2.googleapis.com/token' => Http::response([
                    'access_token' => 'mock-oauth-v1-token',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                ], 200),
                'https://fcm.googleapis.com/v1/projects/halo-apu-test/messages:send' => Http::response([
                    'name' => 'projects/halo-apu-test/messages/msg-001',
                ], 200),
            ]);

            $firstResult = FcmService::sendToToken(
                'device-fcm-token-1',
                'Update Halo APU v1.0.5',
                'Versi baru tersedia',
                [
                    'type' => 'app_update',
                    'channel' => 'staging',
                    'version_name' => '1.0.5',
                    'build_number' => 25,
                ]
            );

            $this->assertTrue($firstResult);
            $this->assertTrue(FcmService::lastSendSucceeded());

            // Second send reuses cached OAuth2 token
            $secondResult = FcmService::sendToToken(
                'device-fcm-token-2',
                'Update Halo APU v1.0.5',
                'Versi baru tersedia',
                ['type' => 'app_update']
            );

            $this->assertTrue($secondResult);

            Http::assertSentCount(3); // 1 OAuth token exchange + 2 FCM v1 message sends

            Http::assertSent(function ($request) {
                if ($request->url() !== 'https://oauth2.googleapis.com/token') {
                    return false;
                }

                $assertion = (string) ($request['assertion'] ?? '');

                return ($request['grant_type'] ?? null) === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                    && substr_count($assertion, '.') === 2;
            });

            Http::assertSent(function ($request) {
                return $request->url() === 'https://fcm.googleapis.com/v1/projects/halo-apu-test/messages:send'
                    && $request->hasHeader('Authorization', 'Bearer mock-oauth-v1-token')
                    && ($request['message']['token'] ?? null) === 'device-fcm-token-1'
                    && ($request['message']['notification']['title'] ?? null) === 'Update Halo APU v1.0.5'
                    && ($request['message']['data']['type'] ?? null) === 'app_update'
                    && ($request['message']['data']['build_number'] ?? null) === '25'
                    && ($request['message']['android']['priority'] ?? null) === 'HIGH';
            });
        } finally {
            @unlink($tempCredsPath);
        }
    }
}
