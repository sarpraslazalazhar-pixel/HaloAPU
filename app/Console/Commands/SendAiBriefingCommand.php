<?php

namespace App\Console\Commands;

use App\Services\Ai\AiBriefingService;
use Illuminate\Console\Command;

class SendAiBriefingCommand extends Command
{
    protected $signature = 'ai:briefing
        {--period=daily : Periode briefing (daily|weekly)}
        {--force : Buat dan kirim ulang meskipun hari ini sudah ada}
        {--admin-id= : Targetkan satu admin ID tertentu}';

    protected $description = 'Buat dan kirim briefing operasional AI (harian/mingguan) ke admin/koordinator';

    public function handle(AiBriefingService $service): int
    {
        $period = strtolower(trim((string) $this->option('period')));
        if (! in_array($period, ['daily', 'weekly'], true)) {
            $this->error("Nilai --period tidak valid: '{$period}'. Gunakan 'daily' atau 'weekly'.");

            return Command::FAILURE;
        }

        $force = (bool) $this->option('force');
        $rawAdminId = $this->option('admin-id');
        $onlyAdminId = null;

        if ($rawAdminId !== null && trim((string) $rawAdminId) !== '') {
            if (! ctype_digit((string) $rawAdminId) || (int) $rawAdminId <= 0) {
                $this->error("Nilai --admin-id tidak valid: '{$rawAdminId}'.");

                return Command::FAILURE;
            }
            $onlyAdminId = (int) $rawAdminId;
        }

        $result = $service->dispatchScheduledBriefings($period, $force, $onlyAdminId);

        if (! $result['config_active'] && ! $force) {
            $this->info("Briefing AI ({$period}) tidak aktif pada konfigurasi reminder.");

            return Command::SUCCESS;
        }

        $this->info(
            "Briefing AI ({$period}) selesai. Terkirim: {$result['sent']}, dilewati: {$result['skipped']}, gagal: {$result['failed']}."
        );

        return Command::SUCCESS;
    }
}
