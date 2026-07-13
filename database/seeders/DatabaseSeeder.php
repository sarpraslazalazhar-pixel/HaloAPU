<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $this->call([
            OrgDivisiSeeder::class,
            OrgUnitSeeder::class,
            OrgJabatanSeeder::class,
            UnitSeeder::class,
            SubUnitSeeder::class,
            FormFieldSeeder::class,
            AdminSeeder::class,
            UserSeeder::class,
            SlaConfigSeeder::class,
            ReminderConfigSeeder::class,
        ]);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
