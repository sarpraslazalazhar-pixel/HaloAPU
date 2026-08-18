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
            'device_id' => 'nullable|string',
            'device_name' => 'nullable|string',
        ]);

        $isAdmin = $request->input('is_admin', false);
        $deviceId = $request->input('device_id');
        $deviceName = $request->input('device_name');

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

        // 1 Akun 1 HP: Validasi Pengikatan Perangkat (Device ID Binding)
        $isDeviceLockEnabled = (bool) \App\Models\SystemConfig::getValue('device_lock_enabled', true);
        $deviceLockTarget = \App\Models\SystemConfig::getValue('device_lock_target', 'all');
        $maxDevices = (int) \App\Models\SystemConfig::getValue('device_lock_max_devices', 1);
        $autoUnlockDays = (int) \App\Models\SystemConfig::getValue('device_lock_auto_unlock_days', 0);

        // Determine if lock applies to this account type
        $lockApplies = $isDeviceLockEnabled && !empty($deviceId) && (
            $deviceLockTarget === 'all' ||
            ($deviceLockTarget === 'user' && !$isAdmin) ||
            ($deviceLockTarget === 'admin' && $isAdmin)
        );

        if ($lockApplies) {
            // Auto-unlock: remove stale devices if auto_unlock_days > 0
            if ($autoUnlockDays > 0) {
                $cutoff = now()->subDays($autoUnlockDays);
                $user->devices()
                    ->where('last_login_at', '<', $cutoff)
                    ->delete();

                // If primary device was among stale ones, clear it
                $primaryStillExists = $user->devices()->where('device_id', $user->device_id)->exists();
                if (!empty($user->device_id) && !$primaryStillExists) {
                    $latestDevice = $user->devices()->orderBy('last_login_at', 'desc')->first();
                    $user->device_id = $latestDevice?->device_id;
                    $user->device_name = $latestDevice?->device_name;
                    $user->save();
                }
            }

            // Check if this device is already registered for this account
            $existingDevice = $user->devices()->where('device_id', $deviceId)->first();

            if ($existingDevice) {
                // Device already known — update last login
                $existingDevice->update([
                    'last_login_at' => now(),
                    'ip_address' => $request->ip(),
                    'device_name' => $deviceName ?? $existingDevice->device_name,
                ]);
            } else {
                // New device — check if max devices reached
                $currentDeviceCount = $user->devices()->count();

                if ($currentDeviceCount >= $maxDevices) {
                    // Rejected: max devices reached
                    $deviceInfo = $user->device_name ? " (" . $user->device_name . ")" : "";
                    return response()->json([
                        'message' => 'Akun ini telah terikat pada ' . $currentDeviceCount . ' perangkat' . $deviceInfo . '. Batas maksimal ' . $maxDevices . ' perangkat per akun. Silakan hubungi Admin untuk reset kunci perangkat.',
                        'error_code' => 'DEVICE_MISMATCH',
                    ], 403);
                }

                // Register new device
                $user->devices()->create([
                    'device_id' => $deviceId,
                    'device_name' => $deviceName ?? 'Smartphone Android',
                    'ip_address' => $request->ip(),
                    'last_login_at' => now(),
                ]);
            }

            // Keep primary device_id/device_name in sync (use the latest device)
            if (empty($user->device_id)) {
                $user->device_id = $deviceId;
                $user->device_name = $deviceName ?? 'Smartphone Android';
                $user->save();
            }
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

    public function storeFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        $user = $request->user();
        if ($user) {
            $user->fcm_token = $request->fcm_token;
            $user->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'FCM token stored successfully'
        ]);
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
     * Delete user account permanently.
     */
    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        // Delete avatar file if exists
        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        // Revoke all tokens
        $user->tokens()->delete();

        // Delete user record from database
        $user->delete();

        return response()->json([
            'message' => 'Akun berhasil dihapus dari database'
        ], 200);
    }

    /**
     * Format user profile data for API response.
     */
    private function formatUserProfile(User $user): array
    {
        $avatarUrl = null;
        if ($user->avatar_path) {
            $avatarUrl = url('api/attachments/serve?path=' . $user->avatar_path);
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
            'avatarUrl' => $avatarUrl ?? '',
        ];
    }

    private function formatAdminProfile(Admin $admin): array
    {
        $avatarUrl = null;
        if ($admin->avatar_path) {
            $avatarUrl = url('api/attachments/serve?path=' . $admin->avatar_path);
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
            'avatarUrl' => $avatarUrl ?? '',
        ];
    }

    /**
     * Get list of users with device binding status for Admin
     */
    public function getAdminUsersList(Request $request)
    {
        $admin = $request->user();
        if (!$admin instanceof Admin) {
            return response()->json(['message' => 'Hanya Admin yang dapat mengakses data ini'], 403);
        }

        $search = $request->query('search', '');
        $query = User::with(['divisi', 'orgUnit', 'jabatan']);

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->paginate($request->get('per_page', 50));

        $formatted = $users->getCollection()->map(function ($u) {
            return [
                'id' => $u->id,
                'name' => $u->name ?? $u->username,
                'username' => $u->username ?? '',
                'email' => $u->email ?? '',
                'department' => $u->orgUnit ? $u->orgUnit->nama_unit_organisasi : ($u->divisi ? $u->divisi->nama_divisi : ''),
                'deviceId' => $u->device_id,
                'deviceName' => $u->device_name,
                'isDeviceLocked' => !empty($u->device_id),
            ];
        });

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
            'device_lock_enabled' => (bool) \App\Models\SystemConfig::getValue('device_lock_enabled', true),
        ], 200);
    }

    /**
     * Reset / Unlock device binding for a user
     */
    public function resetUserDevice(Request $request, $id)
    {
        $admin = $request->user();
        if (!$admin instanceof Admin) {
            return response()->json(['message' => 'Hanya Admin yang dapat membuka kunci perangkat'], 403);
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan'], 404);
        }

        $user->device_id = null;
        $user->device_name = null;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Kunci perangkat user ' . ($user->name ?? $user->username) . ' berhasil dibuka.',
        ], 200);
    }

    /**
     * Reset / Unlock device binding for an admin
     */
    public function resetAdminDevice(Request $request, $id)
    {
        $currentAdmin = $request->user();
        if (!$currentAdmin instanceof Admin) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $admin = Admin::find($id);
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 404);
        }

        $admin->device_id = null;
        $admin->device_name = null;
        $admin->save();

        return response()->json([
            'success' => true,
            'message' => 'Kunci perangkat admin ' . ($admin->name ?? $admin->username) . ' berhasil dibuka.',
        ], 200);
    }

    /**
     * Get device lock setting
     */
    public function getDeviceLockSetting(Request $request)
    {
        $admin = $request->user();
        if (!$admin instanceof Admin) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        return response()->json([
            'enabled' => (bool) \App\Models\SystemConfig::getValue('device_lock_enabled', true),
        ], 200);
    }

    /**
     * Toggle device lock setting
     */
    public function toggleDeviceLockSetting(Request $request)
    {
        $admin = $request->user();
        if (!$admin instanceof Admin) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $enabled = $request->boolean('enabled', true);
        \App\Models\SystemConfig::setValue('device_lock_enabled', $enabled);

        return response()->json([
            'success' => true,
            'enabled' => $enabled,
            'message' => $enabled 
                ? 'Pembatasan 1 Akun 1 HP berhasil diaktifkan.' 
                : 'Pembatasan 1 Akun 1 HP berhasil dinonaktifkan.',
        ], 200);
    }
}
