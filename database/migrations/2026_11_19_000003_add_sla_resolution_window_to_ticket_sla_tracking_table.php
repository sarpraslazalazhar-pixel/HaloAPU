<?php

use App\Models\TicketSlaTracking;
use App\Services\TicketSlaSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_sla_tracking', function (Blueprint $table) {
            $table->dateTime('sla_resolution_deadline')->nullable()->change();
            $table->dateTime('sla_resolution_starts_at')->nullable();
            $table->dateTime('sla_resolution_base_deadline')->nullable();
        });

        TicketSlaTracking::whereNull('resolved_at')
            ->whereHas('ticket', fn ($query) => $query->whereIn('status', [
                'open', 'diajukan', 'baru', 'on_proses', 'in_progress', 'diproses', 'pending', 'need_revision',
            ]))
            ->with('ticket.subUnit.formFields')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $tracking) {
                    $ticket = $tracking->ticket;
                    $subUnit = $ticket?->subUnit;
                    if (! $subUnit) {
                        continue;
                    }

                    try {
                        $inputs = TicketSlaSchedule::inputs($subUnit, $subUnit->formFields, (array) $ticket->form_data);
                        $window = TicketSlaSchedule::window($inputs);
                    } catch (ValidationException) {
                        continue;
                    }
                    if ($window === null) {
                        continue;
                    }

                    $tracking->timestamps = false;
                    $tracking->update([
                        'sla_resolution_starts_at' => $window['start'],
                        'sla_resolution_base_deadline' => $window['end'],
                        'sla_resolution_deadline' => $window['end']->copy()->addSeconds($tracking->total_paused_minutes * 60),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('ticket_sla_tracking', function (Blueprint $table) {
            $table->dropColumn(['sla_resolution_starts_at', 'sla_resolution_base_deadline']);
        });
    }
};
