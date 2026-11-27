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

    public function test_smart_active_track_and_bearer_token_audience_resolution(): void
    {
        $admin = Admin::factory()->create();
        $activeUser = User::factory()->create(['is_active' => true]);
        $inactiveUser = User::factory()->create(['is_active' => false]);

        $adminToken = $admin->createToken('mobile-admin')->plainTextToken;
        $activeUserToken = $activeUser->createToken('mobile-user')->plainTextToken;
        $inactiveUserToken = $inactiveUser->createToken('mobile-inactive')->plainTextToken;

        $baseAttributes = [
            'platform' => 'android',
            'channel' => 'production',
            'download_url' => 'https://github.com/org/repo/releases/download/v1.0.0/app.apk',
            'sha256' => self::VALID_SHA256,
            'is_active' => false,
        ];

        $release14All = AppRelease::create(array_merge($baseAttributes, [
            'target_audience' => AppRelease::TARGET_ALL,
            'version_name' => '1.0.4',
            'build_number' => 14,
        ]))->activate();

        $release15Admins = AppRelease::create(array_merge($baseAttributes, [
            'target_audience' => AppRelease::TARGET_ADMINS,
            'version_name' => '1.0.5-admin',
            'build_number' => 15,
        ]))->activate();

        // Both #14 ('all') and #15 ('admins') remain active concurrently
        $this->assertTrue($release14All->fresh()->is_active);
        $this->assertTrue($release15Admins->fresh()->is_active);

        // Admin Bearer token receives #15 ('admins')
        $this->getJson('/api/app-version', ['Authorization' => "Bearer {$adminToken}"])
            ->assertOk()
            ->assertJsonPath('data.build_number', 15)
            ->assertJsonPath('data.target_audience', 'admins');

        // Active User Bearer token receives #14 ('all')
        $this->getJson('/api/app-version', ['Authorization' => "Bearer {$activeUserToken}"])
            ->assertOk()
            ->assertJsonPath('data.build_number', 14)
            ->assertJsonPath('data.target_audience', 'all');

        // Guest (no token / legacy APK) receives #14 ('all'), never #15 ('admins')
        $this->getJson('/api/app-version')
            ->assertOk()
            ->assertJsonPath('data.build_number', 14)
            ->assertJsonPath('data.target_audience', 'all');

        // Inactive User Bearer token falls back to guest -> #14 ('all')
        $this->getJson('/api/app-version', ['Authorization' => "Bearer {$inactiveUserToken}"])
            ->assertOk()
            ->assertJsonPath('data.build_number', 14)
            ->assertJsonPath('data.target_audience', 'all');

        // Invalid Bearer token never returns 401; falls back cleanly to guest -> #14 ('all')
        $this->getJson('/api/app-version', ['Authorization' => 'Bearer invalid-or-revoked-token'])
            ->assertOk()
            ->assertJsonPath('data.build_number', 14)
            ->assertJsonPath('data.target_audience', 'all');

        // Activating #16 ('users') keeps #14 ('all') and #15 ('admins') active
        $release16Users = AppRelease::create(array_merge($baseAttributes, [
            'target_audience' => AppRelease::TARGET_USERS,
            'version_name' => '1.0.6-user',
            'build_number' => 16,
        ]))->activate();

        $this->assertTrue($release14All->fresh()->is_active);
        $this->assertTrue($release15Admins->fresh()->is_active);
        $this->assertTrue($release16Users->fresh()->is_active);

        $this->getJson('/api/app-version', ['Authorization' => "Bearer {$adminToken}"])
            ->assertOk()
            ->assertJsonPath('data.build_number', 15);

        $this->getJson('/api/app-version', ['Authorization' => "Bearer {$activeUserToken}"])
            ->assertOk()
            ->assertJsonPath('data.build_number', 16)
            ->assertJsonPath('data.target_audience', 'users');

        $this->getJson('/api/app-version')
            ->assertOk()
            ->assertJsonPath('data.build_number', 14);

        // Activating #17 ('all') supersedes & deactivates #14, #15, and #16
        $release17All = AppRelease::create(array_merge($baseAttributes, [
            'target_audience' => AppRelease::TARGET_ALL,
            'version_name' => '1.0.7',
            'build_number' => 17,
        ]))->activate();

        $this->assertFalse($release14All->fresh()->is_active);
        $this->assertFalse($release15Admins->fresh()->is_active);
        $this->assertFalse($release16Users->fresh()->is_active);
        $this->assertTrue($release17All->fresh()->is_active);

        $this->getJson('/api/app-version', ['Authorization' => "Bearer {$adminToken}"])
            ->assertOk()
            ->assertJsonPath('data.build_number', 17);
        $this->getJson('/api/app-version', ['Authorization' => "Bearer {$activeUserToken}"])
            ->assertOk()
            ->assertJsonPath('data.build_number', 17);
        $this->getJson('/api/app-version')
            ->assertOk()
            ->assertJsonPath('data.build_number', 17);
    }

    public function test_admin_only_release_with_no_all_release_active_hides_update_from_users_and_guests(): void
    {
        $admin = Admin::factory()->create();
        $user = User::factory()->create(['is_active' => true]);

        $adminToken = $admin->createToken('mobile-admin')->plainTextToken;
        $userToken = $user->createToken('mobile-user')->plainTextToken;

        AppRelease::create([
            'platform' => 'android',
            'channel' => 'production',
            'target_audience' => AppRelease::TARGET_ADMINS,
            'version_name' => '1.2.0-admin',
            'build_number' => 20,
            'download_url' => 'https://github.com/org/repo/releases/download/v1.2.0/admin.apk',
            'sha256' => self::VALID_SHA256,
            'is_active' => false,
        ])->activate();

        $this->getJson('/api/app-version', ['Authorization' => "Bearer {$adminToken}"])
            ->assertOk()
            ->assertJsonPath('data.build_number', 20)
            ->assertJsonPath('data.target_audience', 'admins');

        $this->getJson('/api/app-version', ['Authorization' => "Bearer {$userToken}"])
            ->assertOk()
            ->assertJson(['data' => null]);

        $this->getJson('/api/app-version')
            ->assertOk()
            ->assertJson(['data' => null]);
    }

    public function test_updating_active_release_target_audience_to_all_deactivates_siblings_and_clears_cache(): void
    {
        $this->withoutMiddleware([PreventRequestForgery::class]);
        $configAdmin = $this->adminWithConfigPermission();

        $userRelease = AppRelease::create([
            'platform' => 'android',
            'channel' => 'production',
            'target_audience' => AppRelease::TARGET_USERS,
            'version_name' => '1.1.0-user',
            'build_number' => 19,
            'download_url' => 'https://github.com/org/repo/releases/download/v1.1.0/user.apk',
            'sha256' => self::VALID_SHA256,
            'is_active' => false,
        ])->activate();

        $adminRelease = AppRelease::create([
            'platform' => 'android',
            'channel' => 'production',
            'target_audience' => AppRelease::TARGET_ADMINS,
            'version_name' => '1.2.1',
            'build_number' => 21,
            'download_url' => 'https://github.com/org/repo/releases/download/v1.2.1/app.apk',
            'sha256' => self::VALID_SHA256,
            'is_active' => false,
        ])->activate();

        // Warm user & guest caches
        $user = User::factory()->create(['is_active' => true]);
        $userToken = $user->createToken('mobile-user')->plainTextToken;
        $this->getJson('/api/app-version', ['Authorization' => "Bearer {$userToken}"])
            ->assertOk()
            ->assertJsonPath('data.build_number', 19);
        $this->getJson('/api/app-version')->assertOk()->assertJson(['data' => null]);

        // Change active #21 from 'admins' to 'all'
        $this->actingAs($configAdmin, 'admin')
            ->put("/admin/app-releases/{$adminRelease->id}", [
                'target_audience' => AppRelease::TARGET_ALL,
                'version_name' => '1.2.1',
                'build_number' => 21,
                'download_url' => 'https://github.com/org/repo/releases/download/v1.2.1/app.apk',
                'sha256' => self::VALID_SHA256,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($userRelease->fresh()->is_active);
        $this->assertTrue($adminRelease->fresh()->is_active);
        $this->assertSame(AppRelease::TARGET_ALL, $adminRelease->fresh()->target_audience);

        // Cache refreshed for both user and guest
        $this->getJson('/api/app-version', ['Authorization' => "Bearer {$userToken}"])
            ->assertOk()
            ->assertJsonPath('data.build_number', 21)
            ->assertJsonPath('data.target_audience', 'all');
        $this->getJson('/api/app-version')
            ->assertOk()
            ->assertJsonPath('data.build_number', 21);
    }

    public function test_notification_target_is_locked_to_release_target_audience(): void
    {
        $this->withoutMiddleware([PreventRequestForgery::class]);
        $configAdmin = $this->adminWithConfigPermission();
        $otherAdmin = Admin::factory()->create();
        $activeUser = User::factory()->create(['is_active' => true]);

        Http::fake();

        $adminOnlyRelease = AppRelease::create([
            'platform' => 'android',
            'channel' => 'production',
            'target_audience' => AppRelease::TARGET_ADMINS,
            'version_name' => '1.3.0-admin',
            'build_number' => 30,
            'download_url' => 'https://github.com/org/repo/releases/download/v1.3.0/admin.apk',
            'sha256' => self::VALID_SHA256,
            'is_active' => false,
        ])->activate();

        // Reject 'all' and 'users' when release is 'admins'
        foreach ([AppRelease::TARGET_ALL, AppRelease::TARGET_USERS] as $invalidTarget) {
            $this->actingAs($configAdmin, 'admin')
                ->post("/admin/app-releases/{$adminOnlyRelease->id}/notify", [
                    'target' => $invalidTarget,
                    'notification_title' => 'Update Admin',
                    'notification_body' => 'Khusus operator.',
                ])
                ->assertSessionHasErrors('target');
        }

        $this->assertSame(0, $activeUser->notifications()->count());
        $this->assertSame(0, $configAdmin->notifications()->count());

        // Succeeds with 'admins' and only notifies admins
        $this->actingAs($configAdmin, 'admin')
            ->post("/admin/app-releases/{$adminOnlyRelease->id}/notify", [
                'target' => AppRelease::TARGET_ADMINS,
                'notification_title' => 'Update Admin',
                'notification_body' => 'Khusus operator.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, $activeUser->notifications()->count());
        $this->assertSame(1, $configAdmin->notifications()->count());
        $this->assertSame(1, $otherAdmin->notifications()->count());

        // User-only release rejects 'admins' and succeeds with 'users'
        $userOnlyRelease = AppRelease::create([
            'platform' => 'android',
            'channel' => 'production',
            'target_audience' => AppRelease::TARGET_USERS,
            'version_name' => '1.3.1-user',
            'build_number' => 31,
            'download_url' => 'https://github.com/org/repo/releases/download/v1.3.1/user.apk',
            'sha256' => self::VALID_SHA256,
            'is_active' => false,
        ])->activate();

        $this->actingAs($configAdmin, 'admin')
            ->post("/admin/app-releases/{$userOnlyRelease->id}/notify", [
                'target' => AppRelease::TARGET_ADMINS,
                'notification_title' => 'Update Pengaju',
                'notification_body' => 'Khusus pengaju.',
            ])
            ->assertSessionHasErrors('target');

        $this->actingAs($configAdmin, 'admin')
            ->post("/admin/app-releases/{$userOnlyRelease->id}/notify", [
                'target' => AppRelease::TARGET_USERS,
                'notification_title' => 'Update Pengaju',
                'notification_body' => 'Khusus pengaju.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, $activeUser->notifications()->count());
        $this->assertSame(1, $configAdmin->notifications()->count());
    }

    public function test_internal_webhook_supports_optional_target_audience_and_defaults_to_all(): void
    {
        config(['services.app_release.webhook_secret' => 'super-secret-release-token']);

        // Omitted target_audience defaults to 'all'
        $this->withHeader('X-Release-Token', 'super-secret-release-token')
            ->postJson('/api/internal/app-releases', [
                'platform' => 'android',
                'channel' => 'staging',
                'version_name' => '1.4.0',
                'build_number' => 40,
                'download_url' => 'https://github.com/org/repo/releases/download/v1.4.0/app.apk',
                'sha256' => self::VALID_SHA256,
                'is_active' => false,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.target_audience', 'all');

        // Explicit target_audience: 'admins'
        $this->withHeader('X-Release-Token', 'super-secret-release-token')
            ->postJson('/api/internal/app-releases', [
                'platform' => 'android',
                'channel' => 'staging',
                'target_audience' => 'admins',
                'version_name' => '1.4.1-admin',
                'build_number' => 41,
                'download_url' => 'https://github.com/org/repo/releases/download/v1.4.1/admin.apk',
                'sha256' => self::VALID_SHA256,
                'is_active' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.target_audience', 'admins');

        $admin = Admin::factory()->create();
        $adminToken = $admin->createToken('mobile-admin')->plainTextToken;

        $this->getJson('/api/app-version?platform=android&channel=staging', [
            'Authorization' => "Bearer {$adminToken}",
        ])
            ->assertOk()
            ->assertJsonPath('data.build_number', 41)
            ->assertJsonPath('data.target_audience', 'admins');

        // Without token, guest does not see admins release
        $this->getJson('/api/app-version?platform=android&channel=staging')
            ->assertOk()
            ->assertJson(['data' => null]);
    }
}
