<?php

namespace App\Services\Ai\Tools;

use App\Models\Admin;
use App\Models\Conversation;
use App\Models\FormField;
use App\Models\Ticket;
use App\Services\Ai\AiAccessScope;

class GetTicketDetailTool implements AiToolInterface
{
    public function __construct(
        private readonly AiAccessScope $scope,
    ) {}

    public function name(): string
    {
        return 'get_ticket_detail';
    }

    public function description(): string
    {
        return 'Mengambil detail lengkap satu tiket berdasarkan ticket_id (mendukung format 123-456-789 atau 123456789): data formulir terpetakan label FormField, status SLA, booking aset, CSAT, 10 log terbaru, dan 10 pesan chat terbaru.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'ticket_id' => [
                    'type' => 'STRING',
                    'description' => 'Nomor atau ID tiket (contoh: 123-456-789 atau 123456789).',
                ],
            ],
            'required' => ['ticket_id'],
        ];
    }

    public function execute(Admin $admin, array $args): AiToolResult
    {
        $rawId = (string) ($args['ticket_id'] ?? '');
        $cleanId = preg_replace('/\D+/', '', $rawId) ?? '';

        if ($cleanId === '') {
            return new AiToolResult([
                'error' => 'Tiket tidak ditemukan atau di luar cakupan akses unit kamu.',
            ]);
        }

        $ticket = Ticket::with([
            'user:id,name,username,divisi_id',
            'user.divisi:id,nama_divisi',
            'createdByAdmin:id,name,username',
            'unit:id,nama_unit',
            'subUnit:id,unit_id,nama_layanan',
            'subUnit.unit:id,nama_unit',
            'assignedAdmin:id,name,username',
            'orgDivisi:id,nama_divisi',
            'slaTracking',
            'booking',
            'csat',
        ])->find((int) $cleanId);

        if (! $ticket || ! $ticket->isAccessibleBy($admin)) {
            return new AiToolResult([
                'error' => 'Tiket tidak ditemukan atau di luar cakupan akses unit kamu.',
            ]);
        }

        $fieldsById = FormField::where('sub_unit_id', $ticket->sub_unit_id)
            ->orderBy('urutan')
            ->get()
            ->keyBy(fn (FormField $f) => (string) $f->id);

        $mappedForm = [];
        $rawForm = is_array($ticket->form_data) ? $ticket->form_data : [];
        foreach ($rawForm as $key => $value) {
            $field = $fieldsById->get((string) $key);
            $label = $field?->label ?? (string) $key;
            if (is_array($value)) {
                $displayVal = implode(', ', array_map(
                    static fn ($item) => is_scalar($item) ? (string) $item : json_encode($item),
                    $value
                ));
            } elseif (is_bool($value)) {
                $displayVal = $value ? 'Ya' : 'Tidak';
            } else {
                $displayVal = (string) $value;
            }

            $mappedForm[] = [
                'field_id' => (string) $key,
                'label' => (string) $label,
                'value' => $displayVal !== '' ? $displayVal : '-',
            ];
        }

        $sla = $ticket->slaTracking;
        $slaData = $sla ? [
            'sla_response_deadline' => $sla->sla_response_deadline?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'sla_resolution_deadline' => $sla->sla_resolution_deadline?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'responded_at' => $sla->responded_at?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'resolved_at' => $sla->resolved_at?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'is_response_breached' => (bool) $sla->is_response_breached,
            'is_resolution_breached' => (bool) $sla->is_resolution_breached,
            'current_tier' => (int) $sla->current_tier,
            'is_paused' => $sla->paused_at !== null || strtolower((string) $ticket->status) === 'pending',
            'total_paused_minutes' => (int) $sla->total_paused_minutes,
        ] : null;

        $booking = $ticket->booking;
        $bookingData = $booking ? [
            'tipe' => (string) $booking->tipe,
            'nama_aset' => (string) $booking->nama_aset,
            'tanggal_mulai' => $booking->tanggal_mulai?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'tanggal_selesai' => $booking->tanggal_selesai?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'status' => (string) $booking->status,
        ] : null;

        $csat = $ticket->csat;
        $csatData = $csat ? [
            'rating' => (int) $csat->rating,
            'komentar' => $csat->komentar,
            'created_at' => $csat->created_at?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
        ] : null;

        $recentLogs = $ticket->logs()
            ->with('admin:id,name,username')
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn ($log) => [
                'aksi' => (string) $log->aksi,
                'catatan' => $log->catatan,
                'actor' => (string) ($log->admin?->name ?? $log->admin?->username ?? 'Sistem'),
                'timestamp' => $log->timestamp?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            ])
            ->values()
            ->all();

        $recentMessages = [];
        $conversation = Conversation::where('type', 'ticket')
            ->where('ticket_id', $ticket->id)
            ->first();

        if ($conversation) {
            $recentMessages = $conversation->messages()
                ->with('sender')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->reverse()
                ->values()
                ->map(fn ($msg) => [
                    'sender' => (string) ($msg->sender?->name ?? $msg->sender?->username ?? 'Pengguna'),
                    'body' => (string) ($msg->body ?? ''),
                    'created_at' => $msg->created_at?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                ])
                ->all();
        }

        return new AiToolResult([
            'ticket_id' => (int) $ticket->id,
            'formatted_id' => $ticket->formatted_id,
            'url' => '/admin/tiket/' . $ticket->id,
            'judul' => $ticket->judul,
            'status' => (string) $ticket->status,
            'priority' => (string) ($ticket->priority ?? '-'),
            'source' => (string) ($ticket->source ?? 'web'),
            'unit' => (string) ($ticket->unit?->nama_unit ?? $ticket->subUnit?->unit?->nama_unit ?? '-'),
            'layanan' => (string) ($ticket->subUnit?->nama_layanan ?? '-'),
            'pemohon' => (string) ($ticket->user?->name ?? $ticket->user?->username ?? $ticket->createdByAdmin?->name ?? $ticket->createdByAdmin?->username ?? '-'),
            'divisi' => (string) ($ticket->orgDivisi?->nama_divisi ?? $ticket->user?->divisi?->nama_divisi ?? '-'),
            'petugas' => (string) ($ticket->assignedAdmin?->name ?? $ticket->assignedAdmin?->username ?? 'Belum ditugaskan'),
            'created_at' => $ticket->created_at?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'updated_at' => $ticket->updated_at?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'form_fields' => $mappedForm,
            'sla' => $slaData,
            'booking' => $bookingData,
            'csat' => $csatData,
            'recent_logs' => $recentLogs,
            'recent_messages' => $recentMessages,
        ]);
    }
}
