<?php

namespace App\Console\Commands;

use App\Models\FormField;
use App\Models\Ticket;
use App\Services\SlaCalculator;
use App\Services\TicketSlaSchedule;
use Carbon\Carbon;
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
        $flaggedSubUnitIds = FormField::where('extends_resolution_sla', true)
            ->where('tipe_field', 'tanggal')
            ->pluck('sub_unit_id')
            ->unique()
            ->values();

        $placeholders = implode(', ', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
        $rows = [];
        $counts = [
            'tanpa_sla_tracking' => 0,
            'sudah_resolved' => 0,
            'tanggal_tidak_valid' => 0,
            'tanpa_sub_unit' => 0,
            'tanggal_form_kosong' => 0,
            'sudah_sesuai' => 0,
        ];
        $alreadyPending = 0;
        $alreadyWithinPolicy = 0;

        Ticket::with(['subUnit.formFields', 'slaTracking'])
            ->whereRaw("LOWER(status) IN ({$placeholders})", self::ACTIVE_STATUSES)
            ->where(function ($query) use ($flaggedSubUnitIds) {
                if ($flaggedSubUnitIds->isNotEmpty()) {
                    $query->whereIn('sub_unit_id', $flaggedSubUnitIds)
                        ->orWhereHas('slaTracking', fn ($sla) => $sla->whereNotNull('sla_resolution_form_deadline'));
                } else {
                    $query->whereHas('slaTracking', fn ($sla) => $sla->whereNotNull('sla_resolution_form_deadline'));
                }
            })
            ->chunkById(200, function ($tickets) use (
                $calculator,
                $dry,
                &$rows,
                &$counts,
                &$alreadyPending,
                &$alreadyWithinPolicy
            ) {
                foreach ($tickets as $ticket) {
                    $sla = $ticket->slaTracking;
                    if (! $sla) {
                        $counts['tanpa_sla_tracking']++;
                        continue;
                    }
                    if ($sla->resolved_at !== null) {
                        $counts['sudah_resolved']++;
                        continue;
                    }
                    if (! $ticket->subUnit) {
                        $counts['tanpa_sub_unit']++;
                        continue;
                    }

                    try {
                        $formDeadline = TicketSlaSchedule::extensionDeadline(TicketSlaSchedule::extensionInput(
                            $ticket->subUnit,
                            $ticket->subUnit->formFields,
                            (array) $ticket->form_data
                        ));
                    } catch (ValidationException $e) {
                        $counts['tanggal_tidak_valid']++;
                        $this->warn("Tiket #{$ticket->id}: tanggal tidak valid, dilewati.");
                        continue;
                    }

                    $expected = $calculator->previewFormDeadline($sla, $formDeadline);
                    $changed = ! $this->sameCarbon($sla->sla_resolution_deadline, $expected['sla_resolution_deadline'])
                        || ! $this->sameCarbon($sla->sla_resolution_policy_deadline, $expected['sla_resolution_policy_deadline'])
                        || ! $this->sameCarbon($sla->sla_resolution_form_deadline, $expected['sla_resolution_form_deadline']);

                    if (! $changed) {
                        if ($formDeadline === null) {
                            $counts['tanggal_form_kosong']++;
                        } else {
                            $counts['sudah_sesuai']++;
                            if ($sla->sla_resolution_deadline === null) {
                                $alreadyPending++;
                            } elseif ($sla->sla_resolution_policy_deadline !== null && $formDeadline->lte($sla->sla_resolution_policy_deadline)) {
                                $alreadyWithinPolicy++;
                            }
                        }
                        continue;
                    }

                    $before = $sla->sla_resolution_deadline?->copy();
                    $beforeForm = $sla->sla_resolution_form_deadline?->copy();

                    if ($dry) {
                        $after = $expected['sla_resolution_deadline'];
                        $afterForm = $expected['sla_resolution_form_deadline'];
                    } else {
                        $fresh = DB::transaction(function () use ($calculator, $sla, $ticket, $formDeadline) {
                            $locked = $sla->newQuery()->lockForUpdate()->find($sla->id);
                            $locked->setRelation('ticket', $ticket);
                            $calculator->applyFormDeadline($locked, $formDeadline);

                            return $locked->fresh();
                        });
                        $after = $fresh->sla_resolution_deadline;
                        $afterForm = $fresh->sla_resolution_form_deadline;
                    }

                    $rows[] = [
                        $ticket->id,
                        $ticket->status,
                        $before ? $before->format('Y-m-d H:i:s') : '-',
                        $after ? $after->format('Y-m-d H:i:s') : '-',
                        $afterForm ? $afterForm->toDateString() : '-',
                        $this->describeChange($before, $after, $beforeForm, $afterForm),
                    ];
                }
            });

        $skipped = $counts['tanpa_sla_tracking']
            + $counts['sudah_resolved']
            + $counts['tanggal_tidak_valid']
            + $counts['tanpa_sub_unit'];
        $unchanged = $counts['tanggal_form_kosong'] + $counts['sudah_sesuai'];

        if ($rows) {
            $this->table(['Tiket', 'Status', 'Deadline lama', 'Deadline baru', 'Tanggal form', 'Keterangan'], $rows);
        }
        $this->info(($dry ? '[DRY RUN] ' : '').count($rows).' tiket berubah, '.$skipped.' dilewati, '.$unchanged.' sudah sesuai/tidak berubah.');

        $details = [];
        foreach ([
            'tanpa_sla_tracking' => 'tanpa SLA tracking',
            'sudah_resolved' => 'sudah resolved',
            'tanggal_tidak_valid' => 'tanggal tidak valid',
            'tanpa_sub_unit' => 'tanpa sub unit',
            'tanggal_form_kosong' => 'tanggal form kosong',
            'sudah_sesuai' => 'sudah sesuai',
        ] as $key => $label) {
            if ($counts[$key] > 0) {
                $suffix = '';
                if ($key === 'sudah_sesuai' && ($alreadyPending > 0 || $alreadyWithinPolicy > 0)) {
                    $sub = array_filter([
                        $alreadyPending > 0 ? "pending: {$alreadyPending}" : null,
                        $alreadyWithinPolicy > 0 ? "<= policy: {$alreadyWithinPolicy}" : null,
                    ]);
                    $suffix = ' ('.implode(', ', $sub).')';
                }
                $details[] = "{$label}: {$counts[$key]}{$suffix}";
            }
        }
        if ($details !== []) {
            $this->line('Rincian dilewati/tidak berubah: '.implode('; ', $details).'.');
        }

        return self::SUCCESS;
    }

    private function sameCarbon(?Carbon $a, ?Carbon $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a->equalTo($b);
    }

    private function describeChange(?Carbon $before, ?Carbon $after, ?Carbon $beforeForm, ?Carbon $afterForm): string
    {
        if ($after === null && $afterForm !== null) {
            return 'Pending (tanggal form disimpan, berlaku saat tiket diproses)';
        }

        if (! $this->sameCarbon($before, $after)) {
            if ($afterForm !== null && $after !== null && $after->equalTo($afterForm)) {
                return ($before !== null && $after->lessThan($before))
                    ? 'Disesuaikan ke tanggal form baru'
                    : 'Diperpanjang mengikuti tanggal form';
            }

            return 'Dikembalikan ke policy SLA';
        }

        if ($afterForm !== null && $after !== null && $afterForm->lte($after)) {
            return 'Tanggal form disimpan (<= policy SLA, deadline tetap)';
        }

        if ($afterForm === null && $beforeForm !== null) {
            return 'Tanggal form dikosongkan (deadline tetap)';
        }

        return 'Metadata SLA disinkronkan (deadline tetap)';
    }
}
