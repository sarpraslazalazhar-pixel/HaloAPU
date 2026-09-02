<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('users')->truncate();

        User::create([
            'username' => 'sarpras',
            'email' => 'sarpraslazalazhar@gmail.com',
            'password' => Hash::make('password'),
            'no_wa' => null,
            'divisi_id' => null,
            'org_unit_id' => null,
            'jabatan_id' => null,
        ]);
    }
}
