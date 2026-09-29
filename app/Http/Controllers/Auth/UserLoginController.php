<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class UserLoginController extends Controller
{
    public function showLoginForm()
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        if (Auth::guard('web')->check()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/UserLogin');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'role' => ['nullable', 'string', 'in:user,admin'],
        ]);

        $field = filter_var($credentials['username'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $loginInput = $credentials['username'];
        $password = $credentials['password'];
        $remember = $request->boolean('remember');
        $selectedRole = $request->input('role');

        $user = User::where($field, $loginInput)->first();
        $admin = Admin::where($field, $loginInput)->first();

        $userValid = false;
        $adminValid = false;

        if ($selectedRole === 'admin') {
            $adminValid = $admin && Hash::check($password, $admin->password);
        } elseif ($selectedRole === 'user') {
            $userValid = $user && Hash::check($password, $user->password);
        } else {
            // Jalankan hash hanya untuk akun yang ada, hindari double hash berurutan jika tidak ada duplikasi akun
            if ($user) {
                $userValid = Hash::check($password, $user->password);
            }
            if ($admin && (!$userValid || $user)) {
                $adminValid = Hash::check($password, $admin->password);
            }
        }

        if (!$userValid && !$adminValid) {
            return back()->withErrors([
                'username' => 'Kredensial yang diberikan tidak cocok dengan data kami.',
            ])->onlyInput('username');
        }

        if ($userValid && $adminValid && !$selectedRole) {
            return back()->with([
                'role_selection_required' => true,
                'available_roles' => [
                    ['key' => 'user', 'label' => 'Pengaju'],
                    ['key' => 'admin', 'label' => 'Admin / Operator'],
                ],
            ])->onlyInput('username');
        }

        if (($adminValid && $selectedRole === 'admin') || ($adminValid && !$userValid)) {
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
            }
            Auth::guard('admin')->login($admin, $remember);
            $request->session()->regenerate();

            $intended = $request->session()->pull('url.intended');
            $target = ($intended && str_contains($intended, '/admin'))
                ? $intended
                : route('admin.dashboard');

            return redirect()->to($target)->with('success', 'Selamat datang kembali, Admin!');
        }

        if (($userValid && $selectedRole === 'user') || ($userValid && !$adminValid)) {
            if (Auth::guard('admin')->check()) {
                Auth::guard('admin')->logout();
            }
            Auth::guard('web')->login($user, $remember);
            $request->session()->regenerate();

            $intended = $request->session()->pull('url.intended');
            $target = ($intended && !str_contains($intended, '/admin'))
                ? $intended
                : route('dashboard');

            return redirect()->to($target)->with('success', 'Selamat datang kembali!');
        }

        return back()->withErrors([
            'username' => 'Pilihan peran tidak valid untuk kredensial ini.',
        ])->onlyInput('username');
    }

    public function logout(Request $request)
    {
        if (Auth::guard('admin')->check()) {
            Auth::guard('admin')->logout();
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    }
}
