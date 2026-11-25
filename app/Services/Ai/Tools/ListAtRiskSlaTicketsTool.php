<?php

namespace App\Services\Ai\Tools;

use App\Models\Admin;
use App\Models\Ticket;
use App\Services\Ai\AiAccessScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ListAtRiskSlaTicketsTool implements AiToolInterface
{
    public function __construct(
        private readonly AiAccessScope $scope,
    ) {}

    public function name(): string
    {
        return 'list_at_risk_sla_tickets';
    }

    public function description(): string
    {
        return 'Menampilkan daftar tiket aktif yang sudah melanggar SLA (breach), mengalami eskalasi tier >= 1, atau mendekati batas deadline SLA dalam N jam ke depan.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'only_breached' => [
                    'type' => 'BOOLEAN',
                    'description' => 'Jika true, hanya tampilkan tiket aktif yang sudah breach SLA. Default false.',
                ],
                'within_hours' => [
                    'type' => 'INTEGER',
                    'description' => 'Ambang jam ke depan untuk mendeteksi deadline mendekat (default 4 jam, maks 168 jam).',
                ],
                'unit_id' => [
                    'type' => 'INTEGER',
                    'description' => 'Filter ID unit layanan tertentu (opsional).',
                ],
                'limit' => [
                    'type' => 'INTEGER',
                    'description' => 'Jumlah maksimal tiket yang ditampilkan (default 10, maks 25).',
                ],
            ],
        ];
    }

    public function execute(Admin $admin, array $args): AiToolResult
    {
        $onlyBreached = filter_var($args['only_breached'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $withinHours = min(168, max(1, (int) ($args['within_hours'] ?? 4)));
        $limit = min(25, max(1, (int) ($args['limit'] ?? 10)));

        $requestedUnitId = isset($args['unit_id']) && $args['unit_id'] !== '' ? (int) $args['unit_id'] : null;
        $unitCheck = $this->scope->filterUnitId($admin, $requestedUnitId);

        $now = Carbon::now('Asia/Jakarta');
        $appNow = $now->copy()->timezone((string) config('app.timezone', 'Asia/Jakarta'));
        $cutoff = $appNow->copy()->addHours($withinHours);

        $query = $this->scope->tickets($admin)
            ->whereIn('tickets.status', ['open', 'on_proses', 'pending', 'need_revision'])
            ->whereHas('slaTracking', function (Builder $slaQuery) use ($onlyBreached, $cutoff): void {
                $slaQuery->where(function (Builder $q) use ($onlyBreached, $cutoff): void {
                    $q->where('is_response_breached', true)
                        ->orWhere('is_resolution_breached', true);

                    if (! $onlyBreached) {
                        $q->orWhere('current_tier', '>=', 1)
                            ->orWhere(function (Builder $unpaused) use ($cutoff): void {
                                $unpaused->whereNull('paused_at')
                                    ->where(function (Builder $deadlines) use ($cutoff): void {
                                        $deadlines->where(function (Builder $resp) use ($cutoff): void {
                                            $resp->whereNull('responded_at')
                                                ->whereNotNull('sla_response_deadline')
                                                ->where('sla_response_deadline', '<=', $cutoff);
                                        })->orWhere(function (Builder $res) use ($cutoff): void {
                                            $res->whereNull('resolved_at')
                                                ->whereNotNull('sla_resolution_deadline')
                                                ->where('sla_resolution_deadline', '<=', $cutoff);
                                        });
                                    });
                            });
                    }
                });
            })
            ->with([
                'slaTracking',
                'subUnit:id,unit_id,nama_layanan',
                'subUnit.unit:id,nama_unit',
                'unit:id,nama_unit',
                'assignedAdmin:id,name,username',
            ]);

        if ($unitCheck['unit_id'] !== null) {
            $this->scope->applyUnitFilterToTickets($query, $unitCheck['unit_id']);
        }

        $candidates = $query->get();

        $matched = $candidates->filter(function (Ticket $ticket) use ($onlyBreached, $cutoff): bool {
            $sla = $ticket->slaTracking;
            if (! $sla) {
                return false;
            }

            $isBreached = (bool) $sla->is_response_breached || (bool) $sla->is_resolution_breached;
            if ($onlyBreached) {
                return $isBreached;
            }

            if ($isBreached || (int) $sla->current_tier >= 1) {
                return true;
            }

            $isPaused = $sla->paused_at !== null || strtolower((string) $ticket->status) === 'pending';
            if ($isPaused) {
                return false;
            }

            $respDue = $sla->responded_at === null && $sla->sla_response_deadline !== null && $sla->sla_response_deadline->lte($cutoff);
            $resDue = $sla->resolved_at === null && $sla->sla_resolution_deadline !== null && $sla->sla_resolution_deadline->lte($cutoff);

            return $respDue || $resDue;
        })->sortBy(function (Ticket $ticket): array {
            $sla = $ticket->slaTracking;
            $breachedScore = ($sla?->is_resolution_breached || $sla?->is_response_breached) ? 0 : 1;
            $tierScore = -((int) ($sla?->current_tier ?? 0));

            $nextDeadline = null;
            if ($sla?->responded_at === null && $sla?->sla_response_deadline !== null) {
                $nextDeadline = $sla->sla_response_deadline->timestamp;
            }
            if ($sla?->resolved_at === null && $sla?->sla_resolution_deadline !== null) {
                $resTs = $sla->sla_resolution_deadline->timestamp;
                $nextDeadline = $nextDeadline === null ? $resTs : min($nextDeadline, $resTs);
            }

            return [$breachedScore, $tierScore, $nextDeadline ?? PHP_INT_MAX, (int) $ticket->id];
        })->values();

        $totalAtRisk = $matched->count();
        $items = $matched->take($limit)->map(function (Ticket $ticket): array {
            $sla = $ticket->slaTracking;
            $isPaused = ($sla?->paused_at !== null) || strtolower((string) $ticket->status) === 'pending';

            return [
                'ticket_id' => (int) $ticket->id,
                'formatted_id' => $ticket->formatted_id,
                'url' => '/admin/tiket/' . $ticket->id,
                'layanan' => (string) ($ticket->subUnit?->nama_layanan ?? '-'),
                'unit' => (string) ($ticket->unit?->nama_unit ?? $ticket->subUnit?->unit?->nama_unit ?? '-'),
                'status' => (string) $ticket->status,
                'priority' => (string) ($ticket->priority ?? '-'),
                'petugas' => (string) ($ticket->assignedAdmin?->name ?? $ticket->assignedAdmin?->username ?? 'Belum ditugaskan'),
                'current_tier' => (int) ($sla?->current_tier ?? 0),
                'is_response_breached' => (bool) ($sla?->is_response_breached ?? false),
                'is_resolution_breached' => (bool) ($sla?->is_resolution_breached ?? false),
                'response_deadline' => $sla?->sla_response_deadline?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                'resolution_deadline' => $sla?->sla_resolution_deadline?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                'is_paused' => $isPaused,
            ];
        })->all();

        $data = [
            'only_breached' => $onlyBreached,
            'within_hours' => $withinHours,
            'total_at_risk' => $totalAtRisk,
            'returned_count' => count($items),
            'tickets' => $items,
        ];

        if ($unitCheck['denied'] && $unitCheck['note'] !== null) {
            $data['scope_note'] = $unitCheck['note'];
        }

        return new AiToolResult($data);
    }
}
