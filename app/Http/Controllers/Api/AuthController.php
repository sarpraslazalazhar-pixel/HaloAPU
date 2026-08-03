<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use App\Models\User;
use App\Models\Admin;

class AuthController extends Controller
{
    /**
     * Login user and return token + full profile data.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
            'is_admin' => 'nullable|boolean',
        ]);

        $isAdmin = $request->input('is_admin', false);

        if ($isAdmin) {
            $user = Admin::where('email', $request->email)
                ->orWhere('username', $request->email)
                ->first();
        } else {
            $user = User::where('email', $request->email)
                ->orWhere('username', $request->email)
                ->first();
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Email/username atau password salah'
            ], 401);
        }

        // Revoke existing tokens for this device if needed, or just create new
        $token = $user->createToken('mobile-app-token')->plainTextToken;

        // Load relationships for full profile data
        if (!$isAdmin) {
            $user->load(['divisi', 'orgUnit', 'jabatan']);
        }

        return response()->json([
            'data' => [
                'user' => $isAdmin ? $this->formatAdminProfile($user) : $this->formatUserProfile($user),
                'token' => $token,
                'role' => $isAdmin ? 'admin' : 'user',
            ],
            'message' => 'Login berhasil'
        ], 200);
    }

    public function profile(Request $request)
    {
        $user = $request->user();
        if ($user instanceof Admin) {
            return response()->json([
                'data' => $this->formatAdminProfile($user),
            ]);
        }
        
        $user->load(['divisi', 'orgUnit', 'jabatan']);

        return response()->json([
            'data' => $this->formatUserProfile($user),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user instanceof Admin;
        $table = $isAdmin ? 'admins' : 'users';

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'username' => 'sometimes|string|max:100|unique:'.$table.',username,' . $user->id,
            'email' => 'sometimes|email|max:100|unique:'.$table.',email,' . $user->id,
            'no_wa' => 'nullable|string|max:20',
        ]);

        $user->fill($validated);
        $user->save();

        if (!$isAdmin) {
            $user->load(['divisi', 'orgUnit', 'jabatan']);
        }

        return response()->json([
            'data' => $isAdmin ? $this->formatAdminProfile($user) : $this->formatUserProfile($user),
            'message' => 'Profil berhasil diperbarui',
        ]);
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:png,jpg,jpeg|max:5120',
        ]);

        $user = $request->user();
        $isAdmin = $user instanceof Admin;
        $prefix = $isAdmin ? 'admin_' : 'user_';

        // Delete old avatar
        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $file = $request->file('avatar');
        $filename = $prefix . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();
        Storage::disk('public')->put('avatars/' . $filename, file_get_contents($file->getPathname()));

        $user->avatar_path = 'avatars/' . $filename;
        $user->save();

        if (!$isAdmin) {
            $user->load(['divisi', 'orgUnit', 'jabatan']);
        }

        return response()->json([
            'data' => $isAdmin ? $this->formatAdminProfile($user) : $this->formatUserProfile($user),
            'message' => 'Foto profil berhasil diperbarui',
        ]);
    }

    /**
     * Change password.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Password lama tidak sesuai',
                'errors' => ['current_password' => ['Password lama tidak sesuai']],
            ], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json([
            'message' => 'Password berhasil diubah',
        ]);
    }

    /**
     * Logout user.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Berhasil keluar'
        ], 200);
    }

    /**
     * Format user profile data for API response.
     */
    private function formatUserProfile(User $user): array
    {
        $avatarUrl = null;
        if ($user->avatar_path) {
            $avatarUrl = url('storage/' . $user->avatar_path);
        }

        return [
            'id' => $user->id,
            'username' => $user->username ?? '',
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->no_wa ?? '',
            'department' => $user->orgUnit ? $user->orgUnit->nama_unit_organisasi : '',
            'division' => $user->divisi ? $user->divisi->nama_divisi : '',
            'position' => $user->jabatan ? $user->jabatan->nama_jabatan : '',
            'avatarUrl' => $avatarUrl ?? 'https://ui-avatars.com/api/?name=' . urlencode($user->name) . '&background=random',
        ];
    }

    private function formatAdminProfile(Admin $admin): array
    {
        $avatarUrl = null;
        if ($admin->avatar_path) {
            $avatarUrl = url('storage/' . $admin->avatar_path);
        }

        $roleName = $admin->getRoleNames()->first() ?? 'Admin';
        $formattedRole = ucwords(str_replace('_', ' ', $roleName));

        return [
            'id' => $admin->id,
            'username' => $admin->username ?? '',
            'name' => $admin->name,
            'email' => $admin->email,
            'phone' => $admin->no_wa ?? '',
            'department' => 'Sistem Administrator',
            'division' => 'Role: ' . $formattedRole,
            'position' => $formattedRole,
            'avatarUrl' => $avatarUrl ?? 'https://ui-avatars.com/api/?name=' . urlencode($admin->name) . '&background=random',
        ];
    }
}
