<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use App\Models\User;
use App\Models\Admin;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordBroker;
use App\Models\SystemConfig;

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
            'fcm_token' => 'nullable|string',
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

        // Account deactivation keeps the row (tickets/chats survive) but blocks
        // every new session. Admin identities are managed separately.
        if (! $isAdmin && ! $user->is_active) {
            return response()->json([
                'message' => 'Akun tidak aktif. Hubungi admin untuk mengaktifkannya kembali.',
                'error_code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        if ($isAdmin) {
            $token = $this->issueLoginToken($request, $user, true, $deviceId, $deviceName);
        } else {
            // Serialize status re-check, device binding, FCM and token issuance so
            // a deactivation cannot interleave with a login that just passed.
            $result = DB::transaction(function () use ($request, $user, $deviceId, $deviceName) {
                /** @var User $locked */
                $locked = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

                if (! $locked->is_active) {
                    return response()->json([
                        'message' => 'Akun tidak aktif. Hubungi admin untuk mengaktifkannya kembali.',
                        'error_code' => 'ACCOUNT_INACTIVE',
                    ], 403);
                }

                return [
                    'user' => $locked,
                    'token' => $this->issueLoginToken($request, $locked, false, $deviceId, $deviceName),
                ];
            });

            if ($result instanceof \Illuminate\Http\JsonResponse) {
                return $result;
            }

            [$user, $token] = [$result['user'], $result['token']];
        }

        if ($token instanceof \Illuminate\Http\JsonResponse) {
            return $token;
        }

        // Load relationships for full profile data
        if (!$isAdmin) {
            $user->load(['divisi', 'orgUnit', 'jabatan']);
        }

        return response()->json([
            'data' => [
                'user' => $isAdmin ? $this->formatAdminProfile($user) : $this->formatUserProfile($user),
                'token' => $token,
                'role' => $isAdmin ? 'admin' : 'user',
                // Public websocket settings so Flutter can subscribe without VITE_*.
                'realtime' => \App\Providers\AppServiceProvider::realtimeConfig(),
            ],
            'message' => 'Login berhasil'
        ], 200);
    }

    /**
     * Finish a validated login: device lock, FCM token, bearer token.
     *
     * @return string|\Illuminate\Http\JsonResponse
     */
    private function issueLoginToken(Request $request, User|Admin $user, bool $isAdmin, ?string $deviceId, ?string $deviceName): string|\Illuminate\Http\JsonResponse
    {
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

        // Save FCM token if provided
        $fcmToken = $request->input('fcm_token');
        if (!empty($fcmToken)) {
            $user->fcm_token = $fcmToken;
            $user->save();
        }

        return $user->createToken('mobile-app-token')->plainTextToken;
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
            'name' => 'sometimes|nullable|string|max:100',
            'username' => 'sometimes|nullable|string|max:100|unique:'.$table.',username,' . $user->id,
            'email' => 'sometimes|nullable|email|max:100|unique:'.$table.',email,' . $user->id,
            'no_wa' => 'nullable|string|max:30',
        ]);

        $user->fill(array_filter($validated, function ($v) {
            return !is_null($v);
        }));
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
        $oldPath = $user->avatar_path ?? ($user->avatar ?? null);
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $file = $request->file('avatar');
        $ext = $file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'jpg');
        $filename = $prefix . $user->id . '_' . time() . '.' . $ext;
        
        Storage::disk('public')->makeDirectory('avatars');
        Storage::disk('public')->put('avatars/' . $filename, file_get_contents($file->getPathname()));

        $avatarPath = 'avatars/' . $filename;
        $user->avatar_path = $avatarPath;
        if (\Illuminate\Support\Facades\Schema::hasColumn($user->getTable(), 'avatar')) {
            $user->avatar = $avatarPath;
        }
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
        $user = $request->user();
        $currentToken = $user?->currentAccessToken();

        if ($currentToken && method_exists($currentToken, 'delete')) {
            $currentToken->delete();
        }

        // Only clear the account's FCM token once no active Sanctum tokens remain
        // so a delayed logout from a stale session cannot wipe a freshly re-logged-in session.
        if ($user && ($user instanceof User || $user instanceof Admin)) {
            $hasNoRemainingTokens = $user->tokens()->doesntExist();
            $matchesSubmittedFcm = $request->filled('fcm_token')
                && $user->fcm_token === $request->input('fcm_token');

            if ($hasNoRemainingTokens && (! $request->filled('fcm_token') || $matchesSubmittedFcm)) {
                if ($user->fcm_token !== null) {
                    $user->fcm_token = null;
                    $user->save();
                }
            }
        }

        return response()->json([
            'message' => 'Berhasil keluar'
        ], 200);
    }

    /**
     * Deactivate the signed-in requester. The row, tickets, chats and files
     * stay; only access is revoked until an admin re-activates the account.
     */
    public function deactivateAccount(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Hanya akun pengaju yang dapat dinonaktifkan.'], 403);
        }

        \App\Services\UserAccountStatusService::setActive($user, false);

        return response()->json([
            'message' => 'Akun berhasil dinonaktifkan.',
        ], 200);
    }

    /**
     * Format user profile data for API response.
     */
    private function formatUserProfile(User $user): array
    {
        $path = $user->avatar_path ?? ($user->avatar ?? null);
        $avatarUrl = null;
        if ($path) {
            $avatarUrl = url('api/attachments/serve?path=' . $path);
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
            'avatar_url' => $avatarUrl ?? '',
            'avatar' => $avatarUrl ?? '',
            'avatar_path' => $path,
        ];
    }

    private function formatAdminProfile(Admin $admin): array
    {
        $path = $admin->avatar_path ?? ($admin->avatar ?? null);
        $avatarUrl = null;
        if ($path) {
            $avatarUrl = url('api/attachments/serve?path=' . $path);
        }

        $roleName = $admin->getRoleNames()->first() ?? 'Admin';
        $formattedRole = ucwords(str_replace('_', ' ', $roleName));

        $isSuperAdmin = $admin->hasRole(['superadmin', 'super_admin', 'Super Admin']) ||
            str_contains(strtolower($formattedRole), 'super admin');
        
        $canAssignOperator = $isSuperAdmin;
        if (!$canAssignOperator && method_exists($admin, 'hasPermissionTo')) {
            try {
                $canAssignOperator = $admin->hasPermissionTo('akses-assign-operator');
            } catch (\Throwable $e) {
                $canAssignOperator = false;
            }
        }

        return [
            'id' => $admin->id,
            'username' => $admin->username ?? '',
            'name' => $admin->name,
            'email' => $admin->email,
            'phone' => $admin->no_wa ?? '',
            'department' => 'Sistem Administrator',
            'division' => 'Role: ' . $formattedRole,
            'position' => $formattedRole,
            'role' => $roleName,
            'isSuperAdmin' => (bool) $isSuperAdmin,
            'canAssignOperator' => (bool) $canAssignOperator,
            'avatarUrl' => $avatarUrl ?? '',
            'avatar_url' => $avatarUrl ?? '',
            'avatar' => $avatarUrl ?? '',
            'avatar_path' => $path,
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

        $canManageAccounts = $admin->can('akses-manajemen-akun');

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
                'is_active' => (bool) $u->is_active,
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
            'can_manage_accounts' => (bool) $canManageAccounts,
        ], 200);
    }

    /**
     * Activate / deactivate a requester account from the admin app.
     */
    public function updateUserStatus(Request $request, User $user)
    {
        $admin = $request->user();

        if (!$admin instanceof Admin || !$admin->can('akses-manajemen-akun')) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $isActive = (bool) $validated['is_active'];
        \App\Services\UserAccountStatusService::setActive($user, $isActive);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'is_active' => $isActive,
            ],
            'message' => $isActive ? 'Akun berhasil diaktifkan.' : 'Akun berhasil dinonaktifkan.',
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

        $user->devices()->delete();
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

        $admin->devices()->delete();
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

    /**
     * Send password reset link to user or admin email.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|string',
            'is_admin' => 'nullable|boolean',
        ]);

        $input = trim((string) $request->input('email'));
        $isAdminReq = $request->boolean('is_admin');
        $isEmail = filter_var($input, FILTER_VALIDATE_EMAIL);

        $user = null;
        $admin = null;

        if ($isAdminReq) {
            $admin = $isEmail
                ? Admin::where('email', $input)->first()
                : Admin::where('username', $input)->first();
        } else {
            $user = $isEmail
                ? User::where('email', $input)->first()
                : User::where('username', $input)->first();

            // Fallback cari di admin jika tidak ditemukan di user
            if (! $user) {
                $admin = $isEmail
                    ? Admin::where('email', $input)->first()
                    : Admin::where('username', $input)->first();
            }
        }

        if (! $user && ! $admin) {
            return response()->json([
                'success' => false,
                'message' => 'Akun dengan email/username tersebut tidak ditemukan dalam sistem.',
            ], 404);
        }

        $sent = false;
        $lastStatus = null;
        $targetEmail = '';

        try {
            if ($admin) {
                $targetEmail = $admin->email;
                $status = PasswordBroker::broker('admins')->sendResetLink(['email' => $admin->email]);
                $lastStatus = $status;
                if ($status === PasswordBroker::RESET_LINK_SENT) {
                    $sent = true;
                }
            } elseif ($user) {
                $targetEmail = $user->email;
                $status = PasswordBroker::broker('users')->sendResetLink(['email' => $user->email]);
                $lastStatus = $status;
                if ($status === PasswordBroker::RESET_LINK_SENT) {
                    $sent = true;
                }
            }
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim email reset password: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim email reset password: ' . $e->getMessage(),
            ], 500);
        }

        if ($sent) {
            $parts = explode('@', $targetEmail);
            $maskedEmail = $targetEmail;
            if (count($parts) === 2 && strlen($parts[0]) > 2) {
                $name = $parts[0];
                $maskedEmail = substr($name, 0, 2).str_repeat('*', max(1, strlen($name) - 3)).substr($name, -1).'@'.$parts[1];
            }

            return response()->json([
                'success' => true,
                'message' => "Tautan reset password telah dikirim ke email {$maskedEmail}.",
            ], 200);
        }

        if ($lastStatus === PasswordBroker::RESET_THROTTLED) {
            return response()->json([
                'success' => false,
                'message' => 'Permintaan reset password terlalu sering. Harap tunggu 1 menit sebelum mencoba lagi.',
            ], 429);
        }

        return response()->json([
            'success' => false,
            'message' => 'Gagal mengirim email reset password. Pastikan server email telah dikonfigurasi.',
        ], 422);
    }

    /**
     * Send contact/support message to admin.
     */
    public function contactAdmin(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:3000',
            'subject' => 'nullable|string|max:200',
            'email' => 'nullable|email',
            'name' => 'nullable|string|max:100',
        ]);

        $authUser = $request->user('sanctum');
        $senderName = $authUser ? ($authUser->name ?? $authUser->username) : (!empty($validated['name']) ? $validated['name'] : 'Pengguna');
        $senderEmail = $authUser ? $authUser->email : (!empty($validated['email']) ? $validated['email'] : '-');
        $subject = !empty($validated['subject']) ? $validated['subject'] : 'Pesan Bantuan';
        $message = $validated['message'];

        Log::info("Hubungi Admin dari {$senderName} ({$senderEmail}): [{$subject}] {$message}");

        // ponytail: notifikasi synchronous via Mail::send dan DB notification; ganti Mailable/Queue jika traffic bantuan melonjak.
        try {
            $adminEmail = SystemConfig::getValue('email_admin', 'sarpras@alazharpeduli.or.id');
            if ($adminEmail) {
                $waktu = now()->format('d/m/Y H:i:s');
                $emailBody = "Halo Tim Admin / Sarpras,\n\n"
                    . "Terdapat pesan bantuan baru yang masuk melalui aplikasi Halo APU Mobile:\n\n"
                    . "--------------------------------------------------\n"
                    . "Pengirim : {$senderName}\n"
                    . "Email    : {$senderEmail}\n"
                    . "Subjek   : {$subject}\n"
                    . "Waktu    : {$waktu}\n"
                    . "--------------------------------------------------\n\n"
                    . "Pesan:\n"
                    . "{$message}\n\n"
                    . "--------------------------------------------------\n"
                    . "Pesan ini dikirim otomatis oleh sistem Halo APU.";

                $mailSubject = "[Halo APU] Pesan Bantuan: " . ($subject ?: 'Bantuan Mobile');

                $mailMessage = (new \Illuminate\Notifications\Messages\MailMessage)
                    ->subject($mailSubject)
                    ->greeting('Halo Tim Admin / Sarpras,')
                    ->line('Terdapat pesan bantuan baru yang masuk melalui aplikasi Halo APU Mobile:')
                    ->line("**Pengirim:** {$senderName}")
                    ->line("**Email:** {$senderEmail}")
                    ->line("**Subjek:** {$subject}")
                    ->line("**Waktu:** {$waktu}")
                    ->line("**Pesan:**\n{$message}")
                    ->line('Pesan ini dikirim otomatis oleh sistem Halo APU.')
                    ->salutation('Salam hormat, Tim Halo APU');

                $htmlBody = (string) app(\Illuminate\Mail\Markdown::class)->render(
                    $mailMessage->markdown,
                    $mailMessage->data()
                );

                Mail::send([], [], function ($mail) use ($adminEmail, $mailSubject, $senderEmail, $senderName, $htmlBody, $emailBody) {
                    $mail->to($adminEmail)
                        ->subject($mailSubject)
                        ->html($htmlBody)
                        ->text($emailBody);
                    if ($senderEmail && filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
                        $mail->replyTo($senderEmail, $senderName);
                    }
                });
            }

            $admins = Admin::all();
            if ($admins->isNotEmpty()) {
                $notif = new class($senderName, $senderEmail, $subject, $message) extends \Illuminate\Notifications\Notification {
                    public function __construct(
                        public string $senderName,
                        public string $senderEmail,
                        public ?string $subject,
                        public string $message
                    ) {}

                    public function via($notifiable): array
                    {
                        return ['database'];
                    }

                    public function toArray($notifiable): array
                    {
                        return [
                            'type' => 'contact_admin',
                            'title' => 'Pesan Bantuan: ' . ($this->subject ?: 'Bantuan'),
                            'message' => "Dari {$this->senderName} ({$this->senderEmail}): " . \Illuminate\Support\Str::limit($this->message, 120),
                            'sender_name' => $this->senderName,
                            'sender_email' => $this->senderEmail,
                            'subject' => $this->subject,
                            'body' => $this->message,
                        ];
                    }
                };

                Notification::send($admins, $notif);
            }
        } catch (\Throwable $e) {
            Log::warning("Gagal mengirim notifikasi/email contactAdmin: " . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Pesan Anda telah berhasil dikirim ke Admin.',
        ], 200);
    }
}
