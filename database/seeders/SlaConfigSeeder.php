<?php

namespace Database\Seeders;

use App\Models\SlaConfig;
use Illuminate\Database\Seeder;

class SlaConfigSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['sub_unit_id' => null, 'priority' => 'Kritis', 'jenis' => 'respon',       'threshold_minutes' => 15],
            ['sub_unit_id' => null, 'priority' => 'Tinggi', 'jenis' => 'respon',       'threshold_minutes' => 30],
            ['sub_unit_id' => null, 'priority' => 'Sedang', 'jenis' => 'respon',       'threshold_minutes' => 60],
            ['sub_unit_id' => null, 'priority' => 'Rendah', 'jenis' => 'respon',       'threshold_minutes' => 120],
            ['sub_unit_id' => null, 'priority' => 'Kritis', 'jenis' => 'penyelesaian', 'threshold_minutes' => 120],
            ['sub_unit_id' => null, 'priority' => 'Tinggi', 'jenis' => 'penyelesaian', 'threshold_minutes' => 240],
            ['sub_unit_id' => null, 'priority' => 'Sedang', 'jenis' => 'penyelesaian', 'threshold_minutes' => 480],
            ['sub_unit_id' => null, 'priority' => 'Rendah', 'jenis' => 'penyelesaian', 'threshold_minutes' => 1440],
        ];

        foreach ($defaults as $config) {
            SlaConfig::updateOrCreate(
                ['sub_unit_id' => $config['sub_unit_id'], 'priority' => $config['priority'], 'jenis' => $config['jenis']],
                $config
            );
        }
    }
}
