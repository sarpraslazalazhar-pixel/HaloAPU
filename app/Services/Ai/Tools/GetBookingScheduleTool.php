<?php

namespace App\Services\Ai\Tools;

use App\Models\Admin;
use App\Models\RoomVehicleBooking;
use App\Services\Ai\AiAccessScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GetBookingScheduleTool implements AiToolInterface
{
    public function __construct(
        private readonly AiAccessScope $scope,
    ) {}

    public function name(): string
    {
        return 'get_booking_schedule';
    }

    public function description(): string
    {
        return 'Mengambil jadwal peminjaman/booking ruang, kendaraan, atau aset termonitor pada rentang tanggal tertentu dalam cakupan akses admin.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'tipe' => [
                    'type' => 'STRING',
                    'description' => 'Filter tipe aset, misalnya ruang atau kendaraan (opsional).',
                ],
                'status' => [
                    'type' => 'STRING',
                    'description' => 'Filter status booking, misalnya open, disetujui, selesai, reject, dibatalkan, atau aktif (opsional).',
                ],
                'date_from' => [
                    'type' => 'STRING',
                    'description' => 'Tanggal mulai format YYYY-MM-DD (default hari ini).',
                ],
                'date_to' => [
                    'type' => 'STRING',
                    'description' => 'Tanggal akhir format YYYY-MM-DD (default +7 hari dari hari ini).',
                ],
                'limit' => [
                    'type' => 'INTEGER',
                    'description' => 'Jumlah maksimal jadwal dikembalikan (default 15, maks 30).',
                ],
            ],
        ];
    }

    public function execute(Admin $admin, array $args): AiToolResult
    {
        $tz = 'Asia/Jakarta';
        $appTz = (string) config('app.timezone', $tz);
        $now = Carbon::now($tz);

        $dateFrom = $this->parseDate($args['date_from'] ?? null, $tz)?->startOfDay()
            ?? $now->copy()->startOfDay();
        $dateTo = $this->parseDate($args['date_to'] ?? null, $tz)?->endOfDay()
            ?? $now->copy()->addDays(7)->endOfDay();

        if ($dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo->copy()->startOfDay(), $dateFrom->copy()->endOfDay()];
        }

        $limit = min(30, max(1, (int) ($args['limit'] ?? 15)));
        $tipe = trim((string) ($args['tipe'] ?? ''));
        $status = trim((string) ($args['status'] ?? ''));

        $startBound = $dateFrom->copy()->timezone($appTz);
        $endBound = $dateTo->copy()->timezone($appTz);

        $query = $this->scope->bookings($admin)
            ->where('room_vehicle_bookings.tanggal_mulai', '<=', $endBound)
            ->where('room_vehicle_bookings.tanggal_selesai', '>=', $startBound);

        if ($tipe !== '') {
            $query->whereRaw('LOWER(room_vehicle_bookings.tipe) = ?', [strtolower($tipe)]);
        }

        if ($status !== '') {
            $normalizedStatus = strtolower($status);
            if ($normalizedStatus === 'aktif') {
                $query->whereNotIn(DB::raw('LOWER(room_vehicle_bookings.status)'), ['reject', 'dibatalkan', 'selesai']);
            } else {
                $query->whereRaw('LOWER(room_vehicle_bookings.status) = ?', [$normalizedStatus]);
            }
        }

        $totalBookings = (clone $query)->count();

        $bookings = $query
            ->with([
                'ticket:id,user_id,created_by_admin_id,unit_id,sub_unit_id,status',
                'ticket.user:id,name,username',
                'ticket.createdByAdmin:id,name,username',
                'ticket.unit:id,nama_unit',
                'ticket.subUnit:id,unit_id,nama_layanan',
                'ticket.subUnit.unit:id,nama_unit',
            ])
            ->orderBy('room_vehicle_bookings.tanggal_mulai')
            ->limit($limit)
            ->get()
            ->map(function (RoomVehicleBooking $booking): array {
                $ticket = $booking->ticket;

                return [
                    'booking_id' => (int) $booking->id,
                    'ticket_id' => (int) $booking->ticket_id,
                    'formatted_id' => $ticket?->formatted_id ?? (string) $booking->ticket_id,
                    'url' => '/admin/tiket/' . $booking->ticket_id,
                    'tipe' => (string) $booking->tipe,
                    'nama_aset' => (string) $booking->nama_aset,
                    'tanggal_mulai' => $booking->tanggal_mulai?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                    'tanggal_selesai' => $booking->tanggal_selesai?->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                    'status' => (string) $booking->status,
                    'ticket_status' => (string) ($ticket?->status ?? '-'),
                    'pemohon' => (string) ($ticket?->user?->name ?? $ticket?->user?->username ?? $ticket?->createdByAdmin?->name ?? $ticket?->createdByAdmin?->username ?? '-'),
                    'layanan' => (string) ($ticket?->subUnit?->nama_layanan ?? '-'),
                    'unit' => (string) ($ticket?->unit?->nama_unit ?? $ticket?->subUnit?->unit?->nama_unit ?? '-'),
                ];
            })
            ->all();

        return new AiToolResult([
            'date_from' => $dateFrom->format('Y-m-d'),
            'date_to' => $dateTo->format('Y-m-d'),
            'tipe_filter' => $tipe !== '' ? $tipe : null,
            'status_filter' => $status !== '' ? $status : null,
            'total_bookings' => $totalBookings,
            'returned_count' => count($bookings),
            'bookings' => $bookings,
        ]);
    }

    private function parseDate(mixed $raw, string $tz): ?Carbon
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($raw), $tz);
        } catch (\Throwable) {
            return null;
        }
    }
}
