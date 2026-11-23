<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\RoomVehicleBooking;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Notifications\TicketCreatedAdminNotification;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Creates admin-submitted tickets. A bulk request is one transaction so a
 * failed row leaves neither partial tickets nor uploaded files behind.
 */
class AdminTicketCreator
{
    public function __construct(private SlaCalculator $slaCalculator) {}

    /**
     * @param  array<int, array{data: array<string, mixed>, subUnit: \App\Models\SubUnit, window: array|null, formDeadline?: \Carbon\Carbon|null}>  $rows
     * @return array<int, Ticket>
     */
    public function create(Admin $admin, array $rows, bool $bulk = false): array
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($admin, $rows, $bulk, &$storedPaths) {
                $tickets = [];

                foreach ($rows as $index => $row) {
                    try {
                        $tickets[] = $this->createTicket($admin, $row, $storedPaths);
                    } catch (ValidationException $e) {
                        if (! $bulk) {
                            throw $e;
                        }

                        throw ValidationException::withMessages(
                            collect($e->errors())
                                ->mapWithKeys(fn (array $messages, string $key) => ["tickets.{$index}.{$key}" => $messages])
                                ->all()
                        );
                    }
                }

                return $tickets;
            });
        } catch (Throwable $e) {
            foreach ($storedPaths as $path) {
                try {
                    if (Storage::disk('public')->delete($path) === false) {
                        Log::warning("Gagal menghapus lampiran tiket yang dibatalkan: {$path}.");
                    }
                } catch (Throwable $cleanupError) {
                    Log::warning("Gagal menghapus lampiran tiket yang dibatalkan: {$path}. ".$cleanupError->getMessage());
                }
            }

            throw $e;
        }
    }

    /**
     * @param  array{data: array<string, mixed>, subUnit: \App\Models\SubUnit, window: array|null, formDeadline?: \Carbon\Carbon|null}  $row
     * @param  array<int, string>  $storedPaths
     */
    private function createTicket(Admin $admin, array $row, array &$storedPaths): Ticket
    {
        $data = $row['data'];
        $subUnit = $row['subUnit'];
        $formData = $data['form_data'];
        $isWorkingHours = $this->slaCalculator->isWithinWorkingHours(now());
        $initialStatus = $isWorkingHours ? 'open' : 'pending';

        $attributes = [
            'user_id' => null,
            'created_by_admin_id' => $admin->id,
            'unit_id' => $data['unit_id'],
            'sub_unit_id' => $data['sub_unit_id'],
            'form_data' => $formData,
            'status' => $initialStatus,
            'assignment_resume_status' => $isWorkingHours ? null : 'open',
            'source' => 'web',
        ];

        if (($data['priority'] ?? null) !== null) {
            $attributes['priority'] = $data['priority'];
        }

        $ticket = Ticket::create($attributes);

        $saveFile = function (UploadedFile $file, ?int $fieldId, bool $wajib) use ($ticket, &$storedPaths): void {
            $path = "ticket-attachments/{$ticket->id}/{$file->hashName()}";

            // Register before writing so a partially written file is cleaned up too.
            $storedPaths[] = $path;

            $stored = Storage::disk('public')->putFileAs(
                "ticket-attachments/{$ticket->id}",
                $file->getPathname(),
                $file->hashName()
            );

            if ($stored === false) {
                throw new RuntimeException("Gagal menyimpan lampiran tiket #{$ticket->id}.");
            }

            TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'field_id' => $fieldId,
                'ticket_log_id' => null,
                'file_path' => $stored,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'wajib' => $wajib,
            ]);
        };

        foreach ((array) ($data['attachments'] ?? []) as $fieldId => $files) {
            if (! is_array($files)) {
                continue;
            }

            $field = $subUnit->formFields->firstWhere('id', $fieldId);
            foreach ($files as $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $saveFile($file, (int) $fieldId, $field ? (bool) $field->wajib : false);
                }
            }
        }

        foreach ((array) ($data['general_attachments'] ?? []) as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $saveFile($file, null, false);
            }
        }

        $this->slaCalculator->createTracking($ticket, $row['window'], $row['formDeadline'] ?? null);

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $admin->id,
            'aksi' => 'dibuat',
            'catatan' => 'Tiket diajukan oleh admin '.($admin->name ?? $admin->username),
        ]);

        if ($subUnit->is_monitored) {
            $this->createBooking($ticket, $subUnit, $formData);
        }

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $formData
     */
    private function createBooking(Ticket $ticket, \App\Models\SubUnit $subUnit, array $formData): void
    {
        $assetName = $formData[(string) $subUnit->monitor_asset_field_id] ?? 'Aset Tidak Diketahui';

        $dateStr = $subUnit->monitor_date_field_id
            ? ($formData[(string) $subUnit->monitor_date_field_id] ?? now()->format('Y-m-d'))
            : null;
        $endDateStr = $subUnit->monitor_end_date_field_id
            ? ($formData[(string) $subUnit->monitor_end_date_field_id] ?? $dateStr ?? now()->format('Y-m-d'))
            : $dateStr;

        $startFieldVal = $formData[(string) $subUnit->monitor_start_field_id] ?? now();
        $endFieldVal = $formData[(string) $subUnit->monitor_end_field_id] ?? now()->addHour();

        if ($dateStr) {
            $startDT = Carbon::parse($dateStr.' '.Carbon::parse($startFieldVal)->format('H:i:s'));
            $endDT = Carbon::parse(($endDateStr ?? $dateStr).' '.Carbon::parse($endFieldVal)->format('H:i:s'));
        } else {
            $startDT = Carbon::parse($startFieldVal);
            $endDT = Carbon::parse($endFieldVal);
        }

        if ($dateStr && $endDateStr && Carbon::parse($endDateStr)->lt(Carbon::parse($dateStr))) {
            $errField = $subUnit->monitor_end_date_field_id ?: $subUnit->monitor_date_field_id;
            throw ValidationException::withMessages([
                'form_data.'.$errField => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            ]);
        }

        if ($endDT->lte($startDT)) {
            $errField = $subUnit->monitor_end_field_id ?: $subUnit->monitor_start_field_id;
            throw ValidationException::withMessages([
                'form_data.'.$errField => 'Waktu selesai harus lebih besar dari waktu mulai.',
            ]);
        }

        if ($startDT->lt(now()->subMinutes(5))) {
            $errField = $subUnit->monitor_date_field_id ?: $subUnit->monitor_start_field_id;
            throw ValidationException::withMessages([
                'form_data.'.$errField => 'Tanggal atau waktu mulai tidak boleh di masa lalu.',
            ]);
        }

        $bentrok = RoomVehicleBooking::where('nama_aset', $assetName)
            ->whereNotIn('status', ['reject', 'dibatalkan', 'selesai'])
            ->where('tanggal_mulai', '<', $endDT)
            ->where('tanggal_selesai', '>', $startDT)
            ->exists();

        if ($bentrok) {
            throw ValidationException::withMessages([
                'form_data.'.$subUnit->monitor_asset_field_id => 'Aset ini sudah dipesan pada jam tersebut.',
            ]);
        }

        RoomVehicleBooking::create([
            'ticket_id' => $ticket->id,
            'tipe' => $subUnit->monitor_kategori ?? 'Lainnya',
            'nama_aset' => $assetName,
            'tanggal_mulai' => $startDT,
            'tanggal_selesai' => $endDT,
            'status' => 'open',
        ]);
    }

    public function notifyAdmins(Ticket $ticket, int $creatorId): void
    {
        try {
            $ticket->load('subUnit');

            $recipients = Admin::where('id', '!=', $creatorId)
                ->where(function ($q) use ($ticket) {
                    $q->whereHas('units', fn ($u) => $u->where('units.id', $ticket->unit_id))
                        ->orWhereHas('roles', fn ($r) => $r->whereIn('name', ['Super Admin', 'superadmin']));
                })
                ->whereDoesntHave('roles', fn ($r) => $r->where('name', 'Operator'))
                ->get();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new TicketCreatedAdminNotification($ticket));
            }
        } catch (Throwable $e) {
            Log::error("Gagal mengirim notifikasi untuk tiket #{$ticket->id}: ".$e->getMessage());
        }
    }
}
