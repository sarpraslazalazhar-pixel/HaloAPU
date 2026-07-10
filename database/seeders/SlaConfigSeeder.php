<?php

namespace Database\Seeders;

use App\Models\SlaConfig;
use Illuminate\Database\Seeder;

class SlaConfigSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['sub_unit_id' => null, 'tier' => 1, 'jenis' => 'respon',       'threshold_minutes' => 30],
            ['sub_unit_id' => null, 'tier' => 2, 'jenis' => 'respon',       'threshold_minutes' => 60],
            ['sub_unit_id' => null, 'tier' => 3, 'jenis' => 'respon',       'threshold_minutes' => 120],
            ['sub_unit_id' => null, 'tier' => 1, 'jenis' => 'penyelesaian', 'threshold_minutes' => 240],
            ['sub_unit_id' => null, 'tier' => 2, 'jenis' => 'penyelesaian', 'threshold_minutes' => 480],
            ['sub_unit_id' => null, 'tier' => 3, 'jenis' => 'penyelesaian', 'threshold_minutes' => 1440],
        ];

        foreach ($defaults as $config) {
            SlaConfig::updateOrCreate(
                ['sub_unit_id' => $config['sub_unit_id'], 'tier' => $config['tier'], 'jenis' => $config['jenis']],
                $config
            );
        }
    }
}
