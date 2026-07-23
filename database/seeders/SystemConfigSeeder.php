<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SystemConfigSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\SystemConfig::setValue('max_revisions', '5');
        \App\Models\SystemConfig::setValue('auto_solve_hours', '48');
    }
}
