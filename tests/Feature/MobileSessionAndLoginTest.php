<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileSessionAndLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_api_login_rate_limit_per_email_returns_429_with_retry_after_and_indonesian_message(): void
    {
        $user = User::factory()->create([
            'email' => 'usera@example.com',
            'password' => Hash::make('secret123'),
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.1'])
                ->postJson('/api/login', [
                    'email' => $user->email,
                    'password' => 'wrong-password',
                ])
                ->assertStatus(401);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.1'])
            ->postJson('/api/login', [
                'email' => '  USERA@EXAMPLE.COM ',
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson([
                'error_code' => 'RATE_LIMITED',
            ]);

        $this->assertGreaterThan(0, (int) $response->json('retry_after'));
        $this->assertStringContainsString('Terlalu banyak percobaan login', (string) $response->json('message'));
    }

    public function test_api_login_rate_limit_on_user_a_does_not_block_user_b_on_same_ip(): void
    {
        $userA = User::factory()->create([
            'email' => 'usera@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $userB = User::factory()->create([
            'email' => 'userb@example.com',
            'password' => Hash::make('secret123'),
        ]);

        // Exhaust User A's 6/min limit from shared office Wi-Fi IP
        for ($i = 0; $i < 6; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
                ->postJson('/api/login', [
                    'email' => $userA->email,
                    'password' => 'wrong-password',
                ])
                ->assertStatus(401);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->postJson('/api/login', [
                'email' => $userA->email,
                'password' => 'wrong-password',
            ])
            ->assertStatus(429);

        // User B on the same IP can still log in cleanly
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->postJson('/api/login', [
                'email' => $userB->email,
                'password' => 'secret123',
            ])
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['token', 'user', 'role'],
            ]);
    }

    public function test_api_login_ip_ceiling_blocks_credential_stuffing_across_different_emails(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
                ->postJson('/api/login', [
                    'email' => "attacker{$i}@example.com",
                    'password' => 'wrong-password',
                ])
                ->assertStatus(401);
        }

        $blocked = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->postJson('/api/login', [
                'email' => 'attacker31@example.com',
                'password' => 'wrong-password',
            ]);

        $blocked->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson([
                'error_code' => 'RATE_LIMITED',
            ]);
    }

    public function test_api_logout_deletes_current_token_and_clears_fcm_token_when_no_other_tokens_exist(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);
        $user->fcm_token = 'fcm-token-device-1';
        $user->save();

        $token = $user->createToken('mobile-app-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJson(['message' => 'Berhasil keluar']);

        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertNull($user->fresh()->fcm_token);
    }

    public function test_api_logout_preserves_fcm_token_when_user_still_has_another_active_token(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);

        // Simulate old token + rapid re-login issuing a newer token with fresh FCM token
        $oldToken = $user->createToken('mobile-app-token-old')->plainTextToken;
        $newToken = $user->createToken('mobile-app-token-new')->plainTextToken;

        $user->fcm_token = 'fcm-token-new-session';
        $user->save();

        // Delayed logout request arrives for the old token
        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->postJson('/api/logout')
            ->assertOk();

        // Old token is removed, new token and its fcm_token remain intact
        $this->assertSame(1, $user->fresh()->tokens()->count());
        $this->assertSame('fcm-token-new-session', $user->fresh()->fcm_token);

        // New token still works
        $this->withHeader('Authorization', 'Bearer '.$newToken)
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_api_logout_also_works_safely_for_admin_accounts(): void
    {
        $admin = Admin::factory()->create([
            'password' => Hash::make('secret123'),
        ]);
        $admin->fcm_token = 'admin-fcm-1';
        $admin->save();

        $token = $admin->createToken('mobile-app-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertSame(0, $admin->fresh()->tokens()->count());
        $this->assertNull($admin->fresh()->fcm_token);
    }

    public function test_web_login_throttle_redirects_with_friendly_username_error_and_does_not_block_other_users_on_same_ip(): void
    {
        $this->withoutMiddleware([PreventRequestForgery::class]);

        $userA = User::factory()->create([
            'username' => 'pegawai_a',
            'password' => Hash::make('secret123'),
        ]);

        $userB = User::factory()->create([
            'username' => 'pegawai_b',
            'password' => Hash::make('secret123'),
        ]);

        // 5 failed attempts for userA
        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')
                ->withServerVariables(['REMOTE_ADDR' => '172.16.0.10'])
                ->post('/login', [
                    'username' => $userA->username,
                    'password' => 'wrong-password',
                ])
                ->assertRedirect('/login')
                ->assertSessionHasErrors('username');
        }

        // 6th attempt returns a friendly validation redirect on username (not raw 429)
        $throttled = $this->from('/login')
            ->withServerVariables(['REMOTE_ADDR' => '172.16.0.10'])
            ->post('/login', [
                'username' => ' PEGAWAI_A ',
                'password' => 'wrong-password',
            ]);

        $throttled->assertRedirect('/login')
            ->assertSessionHasErrors('username');

        $errors = session('errors')->get('username');
        $this->assertStringContainsString('Terlalu banyak percobaan login', $errors[0]);

        // User B on the same IP can still log in cleanly
        $this->from('/login')
            ->withServerVariables(['REMOTE_ADDR' => '172.16.0.10'])
            ->post('/login', [
                'username' => $userB->username,
                'password' => 'secret123',
            ])
            ->assertRedirect(route('dashboard'));
    }
}
