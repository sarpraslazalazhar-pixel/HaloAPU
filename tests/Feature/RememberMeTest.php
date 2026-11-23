<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use App\Services\UserAccountStatusService;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RememberMeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class]);
    }

    private function recallerName(string $guard): string
    {
        $instance = Auth::guard($guard);
        $this->assertInstanceOf(SessionGuard::class, $instance);

        return $instance->getRecallerName();
    }

    private function resetToFreshSessionWithRecaller(string $recallerName, string $encryptedValue): void
    {
        $this->flushSession();
        Auth::forgetGuards();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withUnencryptedCookie($recallerName, $encryptedValue);
    }

    public function test_user_login_with_remember_true_sets_cookie_token_and_shared_prop(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $recallerName = $this->recallerName('web');

        $loginResponse = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
            'role' => 'user',
            'remember' => true,
        ])->assertRedirect(route('dashboard'));

        $cookie = $loginResponse->getCookie($recallerName, false);
        $this->assertNotNull($cookie);
        $this->assertNotEmpty($cookie->getValue());
        $this->assertGreaterThan(time(), $cookie->getExpiresTime());
        $this->assertNotNull($user->fresh()->remember_token);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.remembered.web', true));
    }

    public function test_user_login_with_remember_false_clears_old_recaller_and_sets_prop_false(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
            'remember_token' => null,
        ]);
        $recallerName = $this->recallerName('web');

        $loginResponse = $this->withUnencryptedCookie($recallerName, 'stale-remember-cookie')
            ->post('/login', [
                'username' => $user->username,
                'password' => 'password',
                'role' => 'user',
                'remember' => false,
            ])->assertRedirect(route('dashboard'));

        $cookie = $loginResponse->getCookie($recallerName, false);
        $this->assertNotNull($cookie);
        $this->assertLessThanOrEqual(time(), $cookie->getExpiresTime());
        $this->assertNull($user->fresh()->remember_token);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.remembered.web', false));
    }

    public function test_admin_login_with_remember_true_and_false(): void
    {
        $admin = Admin::factory()->create([
            'password' => bcrypt('password'),
            'remember_token' => null,
        ]);
        $recallerName = $this->recallerName('admin');

        $rememberResponse = $this->post('/admin/login', [
            'username' => $admin->username,
            'password' => 'password',
            'remember' => true,
        ])->assertRedirect(route('admin.dashboard'));

        $cookie = $rememberResponse->getCookie($recallerName, false);
        $this->assertNotNull($cookie);
        $this->assertGreaterThan(time(), $cookie->getExpiresTime());
        $this->assertNotNull($admin->fresh()->remember_token);

        $this->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.remembered.admin', true));

        // Login ulang tanpa centang remember (misal membawa sisa recaller lama) harus membersihkan cookie recaller
        $this->flushSession();
        Auth::forgetGuards();

        $noRememberResponse = $this->withUnencryptedCookie($recallerName, 'stale-admin-remember-cookie')
            ->post('/admin/login', [
                'username' => $admin->username,
                'password' => 'password',
                'remember' => false,
            ])->assertRedirect(route('admin.dashboard'));

        $forgottenCookie = $noRememberResponse->getCookie($recallerName, false);
        $this->assertNotNull($forgottenCookie);
        $this->assertLessThanOrEqual(time(), $forgottenCookie->getExpiresTime());

        $this->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.remembered.admin', false));
    }

    public function test_dual_role_admin_login_via_user_login_route_sets_remember_cookie_and_prop(): void
    {
        $user = User::factory()->create([
            'username' => 'dualrole',
            'email' => 'dualrole@example.test',
            'password' => bcrypt('password'),
            'remember_token' => null,
        ]);
        $admin = Admin::factory()->create([
            'username' => 'dualrole',
            'email' => 'dualrole@example.test',
            'password' => bcrypt('password'),
            'remember_token' => null,
        ]);
        $recallerName = $this->recallerName('admin');

        $loginResponse = $this->post('/login', [
            'username' => 'dualrole',
            'password' => 'password',
            'role' => 'admin',
            'remember' => true,
        ])->assertRedirect(route('admin.dashboard'));

        $cookie = $loginResponse->getCookie($recallerName, false);
        $this->assertNotNull($cookie);
        $this->assertGreaterThan(time(), $cookie->getExpiresTime());
        $this->assertNotNull($admin->fresh()->remember_token);
        $this->assertNull($user->fresh()->remember_token);

        $this->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.remembered.admin', true));
    }

    public function test_user_session_expiry_recovers_via_remember_cookie_and_persists_across_subsequent_requests(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $recallerName = $this->recallerName('web');

        $loginResponse = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
            'role' => 'user',
            'remember' => true,
        ])->assertRedirect(route('dashboard'));

        $recallerCookie = $loginResponse->getCookie($recallerName, false);
        $this->assertNotNull($recallerCookie);

        // Simulasikan session expire: flush session + hapus default cookies, hanya bawa recaller cookie
        $this->resetToFreshSessionWithRecaller($recallerName, $recallerCookie->getValue());

        // Request ke-1: pulih via recaller (viaRemember = true)
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.remembered.web', true));

        // Request ke-2: hapus recaller cookie agar terbukti memakai session yang sudah tersinkron (viaRemember = false)
        $this->unencryptedCookies = [];
        Auth::forgetGuards();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.remembered.web', true));
    }

    public function test_reactivated_user_with_incremented_session_version_recovers_via_remember_cookie(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        UserAccountStatusService::setActive($user, false);
        UserAccountStatusService::setActive($user, true);

        $user->refresh();
        $this->assertTrue($user->is_active);
        $this->assertSame(1, (int) $user->session_version);

        $recallerName = $this->recallerName('web');

        $loginResponse = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
            'role' => 'user',
            'remember' => true,
        ])->assertRedirect(route('dashboard'));

        $recallerCookie = $loginResponse->getCookie($recallerName, false);
        $this->assertNotNull($recallerCookie);

        // Simulasikan session expire & request pertama mengenai endpoint non-Inertia
        $this->resetToFreshSessionWithRecaller($recallerName, $recallerCookie->getValue());

        $this->withCredentials()
            ->getJson(route('notifications.unread-count'))
            ->assertOk();

        $this->assertSame(1, (int) session('user_session_version'));

        // Lanjut ke halaman Inertia pada session yang sama
        $this->unencryptedCookies = [];
        Auth::forgetGuards();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.remembered.web', true));
    }

    public function test_deactivated_user_cannot_recover_session_with_old_remember_cookie(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $recallerName = $this->recallerName('web');

        $loginResponse = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
            'role' => 'user',
            'remember' => true,
        ])->assertRedirect(route('dashboard'));

        $recallerCookie = $loginResponse->getCookie($recallerName, false);
        $this->assertNotNull($recallerCookie);

        // Nonaktifkan akun -> remember_token di-null-kan dan is_active = false
        UserAccountStatusService::setActive($user, false);

        $this->resetToFreshSessionWithRecaller($recallerName, $recallerCookie->getValue());

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_admin_session_expiry_recovers_via_remember_cookie_and_persists_across_subsequent_requests(): void
    {
        $admin = Admin::factory()->create(['password' => bcrypt('password')]);
        $recallerName = $this->recallerName('admin');

        $loginResponse = $this->post('/admin/login', [
            'username' => $admin->username,
            'password' => 'password',
            'remember' => true,
        ])->assertRedirect(route('admin.dashboard'));

        $recallerCookie = $loginResponse->getCookie($recallerName, false);
        $this->assertNotNull($recallerCookie);

        $this->resetToFreshSessionWithRecaller($recallerName, $recallerCookie->getValue());

        // Request ke-1: pulih via recaller
        $this->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.remembered.admin', true));

        // Request ke-2: viaRemember sudah false, auth.remember.admin tetap true dari session
        $this->unencryptedCookies = [];
        Auth::forgetGuards();

        $this->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.remembered.admin', true));
    }

    public function test_explicit_logout_invalidates_remember_cookie_for_user_and_admin(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $userRecaller = $this->recallerName('web');

        $userLogin = $this->post('/login', [
            'username' => $user->username,
            'password' => 'password',
            'role' => 'user',
            'remember' => true,
        ])->assertRedirect(route('dashboard'));
        $oldUserCookie = $userLogin->getCookie($userRecaller, false);
        $this->assertNotNull($oldUserCookie);

        $userLogout = $this->withUnencryptedCookie($userRecaller, $oldUserCookie->getValue())
            ->post('/logout')
            ->assertRedirect('/');
        $expiredUserCookie = $userLogout->getCookie($userRecaller, false);
        $this->assertNotNull($expiredUserCookie);
        $this->assertLessThanOrEqual(time(), $expiredUserCookie->getExpiresTime());

        // Memakai cookie lama setelah logout harus ditolak
        $this->resetToFreshSessionWithRecaller($userRecaller, $oldUserCookie->getValue());
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest('web');

        // Admin explicit logout
        $admin = Admin::factory()->create(['password' => bcrypt('password')]);
        $adminRecaller = $this->recallerName('admin');

        $this->flushSession();
        Auth::forgetGuards();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        $adminLogin = $this->post('/admin/login', [
            'username' => $admin->username,
            'password' => 'password',
            'remember' => true,
        ])->assertRedirect(route('admin.dashboard'));
        $oldAdminCookie = $adminLogin->getCookie($adminRecaller, false);
        $this->assertNotNull($oldAdminCookie);

        $adminLogout = $this->withUnencryptedCookie($adminRecaller, $oldAdminCookie->getValue())
            ->post('/admin/logout')
            ->assertRedirect(route('login'));
        $expiredAdminCookie = $adminLogout->getCookie($adminRecaller, false);
        $this->assertNotNull($expiredAdminCookie);
        $this->assertLessThanOrEqual(time(), $expiredAdminCookie->getExpiresTime());

        $this->resetToFreshSessionWithRecaller($adminRecaller, $oldAdminCookie->getValue());
        $this->get(route('admin.notifications.index'))->assertRedirect(route('login'));
        $this->assertGuest('admin');
    }
}
