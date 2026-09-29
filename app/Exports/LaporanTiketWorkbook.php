<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithProperties;

class LaporanTiketWorkbook implements Export, WithMultipleSheets, WithProperties
{
    public function __construct(
        private array $filters,
        private $admin,
    ) {}

    public function sheets(): array
    {
        return [
            new LaporanTiketExport($this->filters, $this->admin),
            new LaporanTiketRingkasanSheet($this->filters),
        ];
    }

    public function properties(): array
    {
        return [
            'creator'     => 'HaloAPU',
            'title'       => 'Laporan Tiket Helpdesk HaloAPU',
            'description' => 'Laporan tiket yang di-generate dari sistem HaloAPU',
            'company'     => 'HaloAPU',
        ];
    }
}
