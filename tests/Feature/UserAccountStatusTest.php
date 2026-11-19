<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Message;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class UserAccountStatusTest extends TestCase
{
    use RefreshDatabase;

    private function ticketFor(User $user): Ticket
    {
        $unit = Unit::create(['nama_unit' => 'Umum', 'is_aktif' => true]);
        $subUnit = SubUnit::create([
            'unit_id' => $unit->id,
            'nama_layanan' => 'Perbaikan',
            'is_aktif' => true,
        ]);

        return Ticket::create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'sub_unit_id' => $subUnit->id,
            'form_data' => ['judul' => 'AC bocor'],
            'status' => 'open',
        ]);
    }

    private function adminWithAccountPermission(): Admin
    {
        Permission::firstOrCreate(['name' => 'akses-manajemen-akun', 'guard_name' => 'admin']);

        $admin = Admin::factory()->create(['password' => bcrypt('password')]);
        $admin->givePermissionTo('akses-manajemen-akun');

        return $admin;
    }

    public function test_deactivation_keeps_tickets_and_messages(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $ticket = $this->ticketFor($user);

        Message::create([
            'conversation_id' => \App\Models\Conversation::create([
                'user_id' => $user->id,
                'last_message_at' => now(),
            ])->id,
            'sender_type' => User::class,
            'sender_id' => $user->id,
            'ticket_id' => $ticket->id,
            'body' => 'halo',
        ]);

        $token = $user->createToken('mobile-app-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/user/deactivate')
            ->assertOk()
            ->assertJson(['message' => 'Akun berhasil dinonaktifkan.']);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame(1, (int) $fresh->session_version);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertNull($fresh->remember_token);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('messages', ['sender_id' => $user->id, 'body' => 'halo']);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_removed_delete_routes_are_gone(): void
    {
        $user = User::factory()->create();
        $admin = $this->adminWithAccountPermission();

        $this->actingAs($user, 'sanctum')->deleteJson('/api/user')->assertStatus(405);
        $this->actingAs($admin, 'admin')->delete('/admin/manajemen-user/'.$user->id)->assertStatus(405);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_old_web_session_is_rejected_after_deactivation(): void
    {
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class]);

        $user = User::factory()->create(['password' => bcrypt('password')]);

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
            'role' => 'user',
        ])->assertRedirect(route('dashboard'));

        $this->get('/dashboard')->assertOk();

        // Same browser session: the guard picks up the new session version.
        $this->postJson('/api/user/deactivate')->assertOk();

        $this->get('/dashboard')->assertRedirect(route('login'));

        // Reactivation does not restore the old session version.
        $admin = $this->adminWithAccountPermission();
        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/users/'.$user->id.'/status', ['is_active' => true])
            ->assertOk();

        $this->assertTrue($user->fresh()->is_active);
        $this->assertSame(1, (int) $user->fresh()->session_version);
    }

    public function test_bearer_token_issued_before_deactivation_stops_working(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile-app-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/user/deactivate')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_inactive_account_cannot_log_in_until_an_admin_activates_it(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $admin = $this->adminWithAccountPermission();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/users/'.$user->id.'/status', ['is_active' => false])
            ->assertOk();

        $this->postJson('/api/login', [
            'email' => $user->username,
            'password' => 'password',
        ])->assertStatus(403)->assertJson(['error_code' => 'ACCOUNT_INACTIVE']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/users/'.$user->id.'/status', ['is_active' => true])
            ->assertOk();

        $this->postJson('/api/login', [
            'email' => $user->username,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_only_admins_with_account_permission_can_change_status(): void
    {
        $user = User::factory()->create();
        $admin = $this->adminWithAccountPermission();
        $outsider = Admin::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/admin/users/'.$user->id.'/status', ['is_active' => false])
            ->assertStatus(403);

        $this->actingAs($outsider, 'sanctum')
            ->patchJson('/api/admin/users/'.$user->id.'/status', ['is_active' => false])
            ->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/users/'.$user->id.'/status', ['is_active' => 'nope'])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/users/'.$user->id.'/status', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($user->fresh()->is_active);
    }
}
