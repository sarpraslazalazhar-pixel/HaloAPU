<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SystemConfig;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceUnlockTest extends TestCase
{
    use RefreshDatabase;

    private function bindDevice(User $user, string $deviceId): void
    {
        $user->devices()->create([
            'device_id' => $deviceId,
            'device_name' => 'HP Lama',
            'last_login_at' => now(),
        ]);
        $user->update(['device_id' => $deviceId, 'device_name' => 'HP Lama']);
    }

    private function loginAs(User $user, string $deviceId)
    {
        return $this->postJson('/api/login', [
            'email' => $user->username,
            'password' => 'password',
            'device_id' => $deviceId,
            'device_name' => 'HP Baru',
        ]);
    }

    public function test_api_reset_device_allows_login_from_new_phone(): void
    {
        SystemConfig::setValue('device_lock_enabled', true);
        SystemConfig::setValue('device_lock_max_devices', 1);

        $admin = Admin::factory()->create(['password' => bcrypt('password')]);
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $this->bindDevice($user, 'device-lama');

        $this->loginAs($user, 'device-baru')->assertStatus(403)->assertJson(['error_code' => 'DEVICE_MISMATCH']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$user->id}/reset-device")
            ->assertOk();

        $this->assertDatabaseCount('account_devices', 0);
        $this->loginAs($user, 'device-baru')->assertOk();
    }

    public function test_web_reset_device_clears_bindings_and_unlocks_login(): void
    {
        SystemConfig::setValue('device_lock_enabled', true);
        SystemConfig::setValue('device_lock_max_devices', 1);

        $admin = Admin::factory()->create(['password' => bcrypt('password')]);
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $this->bindDevice($user, 'device-lama');

        $this->withoutMiddleware([PreventRequestForgery::class, \Spatie\Permission\Middleware\PermissionMiddleware::class]);

        $this->actingAs($admin, 'admin')
            ->post("/admin/manajemen-user/{$user->id}/reset-device")
            ->assertRedirect();

        $this->assertNull($user->fresh()->device_id);
        $this->assertDatabaseCount('account_devices', 0);
        $this->loginAs($user, 'device-baru')->assertOk();
    }
}
