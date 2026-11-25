<?php

namespace App\Services\Ai\Tools;

use App\Models\Admin;
use App\Models\Ticket;
use App\Services\Ai\AiAccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SearchTicketsTool implements AiToolInterface
{
    public function __construct(
        private readonly AiAccessScope $scope,
    ) {}

    public function name(): string
    {
        return 'search_tickets';
    }

    public function description(): string
    {
        return 'Mencari daftar tiket berdasarkan kata kunci (nomor tiket, nama/username pemohon, nama layanan, atau isi form_data), status, prioritas, unit, dan rentang tanggal.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'query' => [
                    'type' => 'STRING',
                    'description' => 'Kata kunci pencarian: nomor tiket (dengan/tanpa tanda -), nama/username pemohon, layanan, atau isi formulir.',
                ],
                'status' => [
                    'type' => 'STRING',
                    'description' => 'Filter status tiket: open, on_proses, pending, need_revision, waiting_approval, solve, reject, atau dibatalkan.',
                ],
                'priority' => [
                    'type' => 'STRING',
                    'description' => 'Filter prioritas: Rendah, Sedang, Tinggi, atau Urgen.',
                ],
                'unit_id' => [
                    'type' => 'INTEGER',
                    'description' => 'Filter ID unit layanan tertentu (opsional).',
                ],
                'date_from' => [
                    'type' => 'STRING',
                    'description' => 'Tanggal mulai dibuat format YYYY-MM-DD (opsional).',
                ],
                'date_to' => [
                    'type' => 'STRING',
                    'description' => 'Tanggal akhir dibuat format YYYY-MM-DD (opsional).',
                ],
                'limit' => [
                    'type' => 'INTEGER',
                    'description' => 'Jumlah maksimal tiket dikembalikan (default 10, maks 15).',
                ],
            ],
        ];
    }

    public function execute(Admin $admin, array $args): AiToolResult
    {
        $search = trim((string) ($args['query'] ?? ''));
        $status = trim((string) ($args['status'] ?? ''));
        $priority = trim((string) ($args['priority'] ?? ''));
        $limit = min(15, max(1, (int) ($args['limit'] ?? 10)));

        $requestedUnitId = isset($args['unit_id']) && $args['unit_id'] !== '' ? (int) $args['unit_id'] : null;
        $unitCheck = $this->scope->filterUnitId($admin, $requestedUnitId);

        $query = $this->scope->tickets($admin);

        if ($unitCheck['unit_id'] !== null) {
            $this->scope->applyUnitFilterToTickets($query, $unitCheck['unit_id']);
        }

        $dateFrom = isset($args['date_from']) ? (string) $args['date_from'] : null;
        $dateTo = isset($args['date_to']) ? (string) $args['date_to'] : null;
        if (($dateFrom !== null && trim($dateFrom) !== '') || ($dateTo !== null && trim($dateTo) !== '')) {
            $periodInfo = PeriodResolver::resolve(null, $dateFrom, $dateTo, 'all');
            PeriodResolver::applyToQuery($query, 'tickets.created_at', $periodInfo);
        }

        if ($status !== '') {
            $normalizedStatus = strtolower($status);
            if (in_array($normalizedStatus, ['solve', 'selesai'], true)) {
                $query->whereIn(DB::raw('LOWER(tickets.status)'), ['solve', 'selesai']);
            } else {
                $query->whereRaw('LOWER(tickets.status) = ?', [$normalizedStatus]);
            }
        }

        if ($priority !== '') {
            $query->whereRaw('LOWER(tickets.priority) = ?', [strtolower($priority)]);
        }

        if ($search !== '') {
            $cleanDigits = preg_replace('/\D+/', '', $search) ?? '';

            $query->where(function (Builder $q) use ($search, $cleanDigits): void {
                $like = '%' . $search . '%';
                $q->where('tickets.form_data', 'like', $like)
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $like)->orWhere('username', 'like', $like))
                    ->orWhereHas('createdByAdmin', fn (Builder $a) => $a->where('name', 'like', $like)->orWhere('username', 'like', $like))
                    ->orWhereHas('subUnit', fn (Builder $s) => $s->where('nama_layanan', 'like', $like));

                if ($cleanDigits !== '') {
                    $q->orWhere('tickets.id', 'like', '%' . $cleanDigits . '%');
                    if (ctype_digit($cleanDigits) && strlen($cleanDigits) <= 12) {
                        $q->orWhere('tickets.id', '=', (int) $cleanDigits);
                    }
                } else {
                    $q->orWhere('tickets.id', 'like', $like);
                }
            });
        }

        $totalMatched = (clone $query)->count();

        $tickets = $query
            ->with([
                'user:id,name,username',
                'createdByAdmin:id,name,username',
                'assignedAdmin:id,name,username',
                'unit:id,nama_unit',
                'subUnit:id,unit_id,nama_layanan',
                'subUnit.unit:id,nama_unit',
            ])
            ->orderByDesc('tickets.created_at')
            ->orderByDesc('tickets.id')
            ->limit($limit)
            ->get()
            ->map(function (Ticket $ticket): array {
                return [
                    'ticket_id' => (int) $ticket->id,
                    'formatted_id' => $ticket->formatted_id,
                    'url' => '/admin/tiket/' . $ticket->id,
                    'judul' => $ticket->judul,
                    'layanan' => (string) ($ticket->subUnit?->nama_layanan ?? '-'),
                    'unit' => (string) ($ticket->unit?->nama_unit ?? $ticket->subUnit?->unit?->nama_unit ?? '-'),
                    'pemohon' => (string) ($ticket->user?->name ?? $ticket->user?->username ?? $ticket->createdByAdmin?->name ?? $ticket->createdByAdmin?->username ?? '-'),
                    'petugas' => (string) ($ticket->assignedAdmin?->name ?? $ticket->assignedAdmin?->username ?? 'Belum ditugaskan'),
                    'status' => (string) $ticket->status,
                    'priority' => (string) ($ticket->priority ?? '-'),
                    'created_at' => $ticket->created_at?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                ];
            })
            ->all();

        $data = [
            'query' => $search !== '' ? $search : null,
            'total_matched' => $totalMatched,
            'returned_count' => count($tickets),
            'tickets' => $tickets,
        ];

        if ($unitCheck['denied'] && $unitCheck['note'] !== null) {
            $data['scope_note'] = $unitCheck['note'];
        }

        return new AiToolResult($data);
    }
}
