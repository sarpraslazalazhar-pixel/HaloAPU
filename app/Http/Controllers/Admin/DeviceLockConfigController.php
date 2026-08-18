<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountDevice;
use App\Models\Admin;
use App\Models\SystemConfig;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeviceLockConfigController extends Controller
{
    /**
     * Display the device lock configuration page.
     */
    public function index(Request $request)
    {
        // Load config values
        $configs = [
            'device_lock_enabled' => (bool) SystemConfig::getValue('device_lock_enabled', true),
            'device_lock_target' => SystemConfig::getValue('device_lock_target', 'all'),
            'device_lock_max_devices' => (int) SystemConfig::getValue('device_lock_max_devices', 1),
            'device_lock_auto_unlock_days' => (int) SystemConfig::getValue('device_lock_auto_unlock_days', 0),
        ];

        // Statistics
        $totalUsers = User::count();
        $totalAdmins = Admin::count();
        $usersWithDevice = User::whereNotNull('device_id')->count();
        $adminsWithDevice = Admin::whereNotNull('device_id')->count();
        $totalDevices = AccountDevice::count();

        $stats = [
            'total_accounts' => $totalUsers + $totalAdmins,
            'accounts_locked' => $usersWithDevice + $adminsWithDevice,
            'total_devices' => $totalDevices,
            'total_users' => $totalUsers,
            'total_admins' => $totalAdmins,
            'users_locked' => $usersWithDevice,
            'admins_locked' => $adminsWithDevice,
        ];

        // Build devices list with search/filter
        $search = $request->query('search', '');
        $typeFilter = $request->query('type', 'all'); // all, user, admin
        $statusFilter = $request->query('status', 'all'); // all, locked, unlocked

        $devices = collect();

        // Gather Users
        if ($typeFilter === 'all' || $typeFilter === 'user') {
            $userQuery = User::with(['divisi', 'orgUnit', 'jabatan', 'devices']);

            if (!empty($search)) {
                $userQuery->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('username', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('device_name', 'like', "%{$search}%");
                });
            }

            if ($statusFilter === 'locked') {
                $userQuery->whereNotNull('device_id');
            } elseif ($statusFilter === 'unlocked') {
                $userQuery->whereNull('device_id');
            }

            $userQuery->orderBy('name')->get()->each(function ($user) use (&$devices) {
                $devices->push([
                    'id' => $user->id,
                    'account_type' => 'user',
                    'account_type_label' => 'User',
                    'name' => $user->name ?? $user->username,
                    'username' => $user->username ?? '',
                    'email' => $user->email ?? '',
                    'department' => $user->orgUnit?->nama_unit_organisasi ?? ($user->divisi?->nama_divisi ?? '-'),
                    'device_id' => $user->device_id,
                    'device_name' => $user->device_name,
                    'is_locked' => !empty($user->device_id),
                    'devices' => $user->devices->map(function ($d) {
                        return [
                            'id' => $d->id,
                            'device_id' => $d->device_id,
                            'device_name' => $d->device_name,
                            'ip_address' => $d->ip_address,
                            'last_login_at' => $d->last_login_at?->toDateTimeString(),
                        ];
                    })->values()->toArray(),
                    'device_count' => $user->devices->count(),
                ]);
            });
        }

        // Gather Admins
        if ($typeFilter === 'all' || $typeFilter === 'admin') {
            $adminQuery = Admin::with(['devices']);

            if (!empty($search)) {
                $adminQuery->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('username', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('device_name', 'like', "%{$search}%");
                });
            }

            if ($statusFilter === 'locked') {
                $adminQuery->whereNotNull('device_id');
            } elseif ($statusFilter === 'unlocked') {
                $adminQuery->whereNull('device_id');
            }

            $adminQuery->orderBy('username')->get()->each(function ($admin) use (&$devices) {
                $roleName = $admin->getRoleNames()->first() ?? 'Admin';

                $devices->push([
                    'id' => $admin->id,
                    'account_type' => 'admin',
                    'account_type_label' => 'Operator',
                    'name' => $admin->name ?? $admin->username,
                    'username' => $admin->username ?? '',
                    'email' => $admin->email ?? '',
                    'department' => 'Role: ' . ucwords(str_replace('_', ' ', $roleName)),
                    'device_id' => $admin->device_id,
                    'device_name' => $admin->device_name,
                    'is_locked' => !empty($admin->device_id),
                    'devices' => $admin->devices->map(function ($d) {
                        return [
                            'id' => $d->id,
                            'device_id' => $d->device_id,
                            'device_name' => $d->device_name,
                            'ip_address' => $d->ip_address,
                            'last_login_at' => $d->last_login_at?->toDateTimeString(),
                        ];
                    })->values()->toArray(),
                    'device_count' => $admin->devices->count(),
                ]);
            });
        }

        return Inertia::render('Admin/DeviceLockConfig/Index', [
            'configs' => $configs,
            'stats' => $stats,
            'accounts' => $devices->values()->toArray(),
            'filters' => [
                'search' => $search,
                'type' => $typeFilter,
                'status' => $statusFilter,
            ],
        ]);
    }

    /**
     * Update device lock policy settings.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'device_lock_enabled' => 'required|boolean',
            'device_lock_target' => 'required|in:all,user,admin',
            'device_lock_max_devices' => 'required|integer|min:1|max:5',
            'device_lock_auto_unlock_days' => 'required|integer|min:0|max:365',
        ]);

        SystemConfig::setValue('device_lock_enabled', $validated['device_lock_enabled'] ? '1' : '0');
        SystemConfig::setValue('device_lock_target', $validated['device_lock_target']);
        SystemConfig::setValue('device_lock_max_devices', (string) $validated['device_lock_max_devices']);
        SystemConfig::setValue('device_lock_auto_unlock_days', (string) $validated['device_lock_auto_unlock_days']);

        return back()->with('success', 'Pengaturan Peraturan Lock Device berhasil disimpan.');
    }

    /**
     * Reset all devices for a specific account.
     */
    public function resetAccountDevices(Request $request, string $type, int $id)
    {
        if ($type === 'user') {
            $account = User::findOrFail($id);
        } elseif ($type === 'admin') {
            $account = Admin::findOrFail($id);
        } else {
            return back()->withErrors(['error' => 'Tipe akun tidak valid.']);
        }

        $account->update([
            'device_id' => null,
            'device_name' => null,
        ]);

        // Remove from account_devices table
        $account->devices()->delete();

        $label = $type === 'admin' ? 'Operator' : 'User';
        return back()->with('success', "Semua perangkat {$label} \"{$account->name}\" berhasil di-reset.");
    }

    /**
     * Revoke a single device record.
     */
    public function revokeDevice(Request $request, int $deviceId)
    {
        $device = AccountDevice::findOrFail($deviceId);
        $account = $device->authenticatable;

        // If the revoked device is the primary one, also clear the columns on the account
        if ($account && $account->device_id === $device->device_id) {
            // If there are other devices, promote one; otherwise clear
            $otherDevice = $account->devices()->where('id', '!=', $device->id)->orderBy('last_login_at', 'desc')->first();

            if ($otherDevice) {
                $account->update([
                    'device_id' => $otherDevice->device_id,
                    'device_name' => $otherDevice->device_name,
                ]);
            } else {
                $account->update([
                    'device_id' => null,
                    'device_name' => null,
                ]);
            }
        }

        $device->delete();

        return back()->with('success', "Perangkat \"{$device->device_name}\" berhasil dicabut.");
    }

    /**
     * Reset ALL devices in the system (bulk action).
     */
    public function resetAllDevices(Request $request)
    {
        // Clear device columns on all users and admins
        User::whereNotNull('device_id')->update([
            'device_id' => null,
            'device_name' => null,
        ]);

        Admin::whereNotNull('device_id')->update([
            'device_id' => null,
            'device_name' => null,
        ]);

        // Delete all device records
        AccountDevice::truncate();

        return back()->with('success', 'Semua perangkat di seluruh sistem berhasil di-reset.');
    }
}
