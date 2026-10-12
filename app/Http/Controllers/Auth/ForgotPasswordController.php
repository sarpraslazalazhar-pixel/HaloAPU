<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ForgotPasswordController extends Controller
{
    public function showForm()
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $email = $request->only('email');
        $hasUser = User::where('email', $request->email)->exists();
        $hasAdmin = Admin::where('email', $request->email)->exists();

        if (!$hasUser && !$hasAdmin) {
            return back()->withErrors(['email' => __('passwords.user')]);
        }

        $sent = false;
        $lastStatus = null;

        if ($hasUser) {
            $status = Password::broker('users')->sendResetLink($email);
            if ($status === Password::RESET_LINK_SENT) {
                $sent = true;
            }
            $lastStatus = $status;
        }

        if ($hasAdmin) {
            $status = Password::broker('admins')->sendResetLink($email);
            if ($status === Password::RESET_LINK_SENT) {
                $sent = true;
            }
            $lastStatus = $status;
        }

        return $sent
            ? back()->with('status', 'Link reset password telah dikirim ke email kamu.')
            : back()->withErrors(['email' => __($lastStatus)]);
    }

    public function showResetForm(Request $request, $token)
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->email,
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:6|confirmed',
        ]);

        $credentials = $request->only('email', 'password', 'password_confirmation', 'token');

        $status = Password::broker('users')->reset(
            $credentials,
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(\Illuminate\Support\Str::random(60));

                $user->save();
            }
        );

        if ($status === Password::INVALID_USER) {
            $status = Password::broker('admins')->reset(
                $credentials,
                function ($admin, $password) {
                    $admin->forceFill([
                        'password' => Hash::make($password)
                    ])->setRememberToken(\Illuminate\Support\Str::random(60));

                    $admin->save();
                }
            );
        }

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('success', 'Password berhasil direset. Silakan masuk dengan password baru.')
            : back()->withErrors(['email' => __($status)]);
    }
}
