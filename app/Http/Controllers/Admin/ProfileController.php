<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $admin = $request->user('admin');

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'username' => 'required|string|max:100|unique:admins,username,' . $admin->id,
            'email' => 'required|email|max:100|unique:admins,email,' . $admin->id,
            'no_wa' => 'nullable|string|max:20',
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        $admin->name = $validated['name'];
        $admin->username = $validated['username'];
        $admin->email = $validated['email'];
        $admin->no_wa = $validated['no_wa'];

        if (!empty($validated['password'])) {
            $admin->password = Hash::make($validated['password']);
        }

        $admin->save();

        return back()->with('success', 'Profil berhasil diperbarui.');
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:png,jpg,jpeg|max:5120',
        ]);

        $admin = $request->user('admin');

        // Hapus avatar lama
        if ($admin->avatar_path && Storage::disk('public')->exists($admin->avatar_path)) {
            Storage::disk('public')->delete($admin->avatar_path);
        }

        $file = $request->file('avatar');
        $filename = 'admin_' . $admin->id . '_' . time() . '.' . $file->getClientOriginalExtension();
        Storage::disk('public')->put('avatars/' . $filename, file_get_contents($file->getPathname()));

        $admin->avatar_path = 'avatars/' . $filename;
        $admin->save();

        return back()->with('success', 'Foto profil berhasil diperbarui.');
    }
}
