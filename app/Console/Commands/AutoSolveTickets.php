<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tickets:auto-solve')]
#[Description('Perintah ini sudah tidak digunakan. Fitur revisi sekarang berbasis status solve tanpa batas waktu.')]
class AutoSolveTickets extends Command
{
    public function handle()
    {
        $this->info('Perintah ini sudah tidak digunakan. Fitur revisi sekarang berbasis status solve.');
    }
}
