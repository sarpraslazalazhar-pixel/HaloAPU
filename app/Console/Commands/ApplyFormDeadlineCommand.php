<?php

namespace App\Console\Commands;

use App\Models\FormField;
use App\Models\Ticket;
use App\Services\SlaCalculator;
use App\Services\TicketSlaSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyFormDeadlineCommand extends Command
{
    protected $signature = 'sla:apply-form-deadline {--dry-run : Tampilkan perubahan tanpa menyimpan}';

    protected $description = 'Terapkan tanggal form bertanda ke SLA penyelesaian tiket aktif yang sudah ada';

    private const ACTIVE_STATUSES = ['open', 'diajukan', 'baru', 'on_proses', 'in_progress', 'diproses', 'pending', 'need_revision'];

    public function handle(SlaCalculator $calculator): int
    {
        $dry = (bool) $this->option('dry-run');
        $subUnitIds = FormField::where('extends_resolution_sla', true)->where('tipe_field', 'tanggal')->pluck('sub_unit_id');

        $rows = [];
        $skipped = 0;

        Ticket::with(['subUnit.formFields', 'slaTracking'])
            ->whereIn('sub_unit_id', $subUnitIds)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->chunkById(200, function ($tickets) use ($calculator, $dry, &$rows, &$skipped) {
                foreach ($tickets as $ticket) {
                    $sla = $ticket->slaTracking;
                    if (! $sla || $sla->resolved_at !== null || ! $ticket->subUnit) {
                        continue;
                    }

                    try {
                        $formDeadline = TicketSlaSchedule::extensionDeadline(TicketSlaSchedule::extensionInput(
                            $ticket->subUnit, $ticket->subUnit->formFields, (array) $ticket->form_data
                        ));
                    } catch (ValidationException $e) {
                        $skipped++;
                        $this->warn("Tiket #{$ticket->id}: tanggal tidak valid, dilewati.");
                        continue;
                    }

                    // Sudah sama → tidak perlu diubah (idempoten).
                    $current = $sla->sla_resolution_form_deadline;
                    if (($current === null && $formDeadline === null) || ($current && $formDeadline && $current->equalTo($formDeadline))) {
                        continue;
                    }

                    $before = $sla->sla_resolution_deadline;
                    if ($dry) {
                        $after = $formDeadline && $before && $formDeadline->greaterThan($before) ? $formDeadline : $before;
                    } else {
                        DB::transaction(function () use ($calculator, $sla, $ticket, $formDeadline) {
                            $locked = $sla->newQuery()->lockForUpdate()->find($sla->id);
                            $locked->setRelation('ticket', $ticket);
                            $calculator->applyFormDeadline($locked, $formDeadline);
                        });
                        $after = $sla->fresh()->sla_resolution_deadline;
                    }

                    if ((string) $before !== (string) $after) {
                        $rows[] = [$ticket->id, $ticket->status, (string) $before ?: '-', (string) $after ?: '-'];
                    }
                }
            });

        if ($rows) {
            $this->table(['Tiket', 'Status', 'Deadline lama', 'Deadline baru'], $rows);
        }
        $this->info(($dry ? '[DRY RUN] ' : '').count($rows).' tiket berubah, '.$skipped.' dilewati.');

        return self::SUCCESS;
    }
}
