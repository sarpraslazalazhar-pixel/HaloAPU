<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\FormField;
use App\Models\OrgDivisi;
use App\Models\OrgJabatan;
use App\Models\RoomVehicleBooking;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use App\Services\SlaCalculator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use App\Notifications\TicketCreatedUserNotification;
use App\Notifications\TicketCreatedAdminNotification;
use Inertia\Inertia;

class TicketWizardController extends Controller
{
    public function create()
    {
        return Inertia::render('User/Tiket/Wizard', [
            'unitList' => Unit::where('aktif', true)->orderBy('nama_unit')->get(),
        ]);
    }

    public function store(Request $request, SlaCalculator $slaCalculator)
    {
        // Decode form_data from JSON string (prevents FormData nesting loss with files)
        if (is_string($request->form_data)) {
            $request->merge([
                'form_data' => json_decode($request->form_data, true) ?? [],
            ]);
        }

        // Validasi dasar
        $request->validate([
            'unit_id' => 'required|exists:units,id',
            'sub_unit_id' => 'required|exists:sub_units,id',
            'form_data' => 'required|array',
            'attachments' => 'nullable|array',
            'attachments.*' => 'nullable|array|max:3',
            'attachments.*.*' => 'file|max:3072', // max 3MB per file
            'general_attachments' => 'nullable|array|max:3',
            'general_attachments.*' => 'file|max:3072|mimes:jpg,jpeg,png,pdf,doc,docx',
        ]);

        // Validasi form_data berdasarkan form_fields yang wajib (hanya yg visible)
        $formFields = FormField::where('sub_unit_id', $request->sub_unit_id)->get();
        foreach ($formFields as $field) {
            if (!$field->wajib || $field->isUpload() || $field->tipe_field === 'info_peraturan') continue;
            // Skip hidden conditional fields
            if ($field->parent_field_id) {
                $parentValue = $request->form_data[(string) $field->parent_field_id] ?? null;
                if ($parentValue !== $field->trigger_value) continue;
            }
            $fieldKey = (string) $field->id;
            if (!isset($request->form_data[$fieldKey]) || empty($request->form_data[$fieldKey])) {
                return back()->withErrors([
                    'form_data.' . $fieldKey => "Field \"{$field->label}\" wajib diisi.",
                ]);
            }
        }

        $ticket = DB::transaction(function () use ($request, $formFields, $slaCalculator) {
            $isWorkingHours = $slaCalculator->isWithinWorkingHours(now());
            $initialStatus = $isWorkingHours ? 'open' : 'pending';

            $user = auth()->user();

            // 1. Buat tiket
            $ticket = Ticket::create([
                'user_id' => $user->id,
                'divisi_id' => $user->divisi_id,
                'org_unit_id' => $user->org_unit_id,
                'jabatan_id' => $user->jabatan_id,
                'unit_id' => $request->unit_id,
                'sub_unit_id' => $request->sub_unit_id,
                'form_data' => $request->form_data,
                'status' => $initialStatus,
            ]);

            // 2. Simpan attachments
            $uploadedFiles = $request->file('attachments');
            if (!empty($uploadedFiles) && is_array($uploadedFiles)) {
                foreach ($uploadedFiles as $fieldId => $filesArray) {
                    if (!is_array($filesArray)) continue;
                    $field = $formFields->firstWhere('id', $fieldId);
                    
                    foreach ($filesArray as $file) {
                        if (!$file || !is_a($file, \Illuminate\Http\UploadedFile::class) || !$file->isValid()) {
                            \Log::error("Invalid file upload for field $fieldId", [
                                'is_file' => $file ? is_a($file, \Illuminate\Http\UploadedFile::class) : false,
                                'valid' => $file ? $file->isValid() : false
                            ]);
                            continue;
                        }

                        // Bypass getRealPath() bug in Windows by passing string path
                        $path = \Illuminate\Support\Facades\Storage::disk('public')->putFileAs(
                            "ticket-attachments/{$ticket->id}",
                            $file->getPathname(),
                            $file->hashName()
                        );

                        TicketAttachment::create([
                            'ticket_id' => $ticket->id,
                            'field_id' => $fieldId,
                            'file_path' => $path,
                            'original_name' => $file->getClientOriginalName(),
                            'mime_type' => $file->getMimeType(),
                            'file_size' => $file->getSize(),
                            'wajib' => $field ? $field->wajib : false,
                        ]);
                    }
                }
            }

            // Simpan general attachments
            $generalFiles = $request->file('general_attachments');
            if (!empty($generalFiles) && is_array($generalFiles)) {
                foreach ($generalFiles as $file) {
                    if (!$file || !is_a($file, \Illuminate\Http\UploadedFile::class) || !$file->isValid()) continue;

                    $path = \Illuminate\Support\Facades\Storage::disk('public')->putFileAs(
                        "ticket-attachments/{$ticket->id}",
                        $file->getPathname(),
                        $file->hashName()
                    );

                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'field_id' => null,
                        'ticket_log_id' => null,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'wajib' => false,
                    ]);
                }
            }

            // 3. Buat SLA tracking
            $responseDeadline = $slaCalculator->calculateResponseDeadline($ticket);
            $resolutionDeadline = $slaCalculator->calculateResolutionDeadline($ticket);

            TicketSlaTracking::create([
                'ticket_id' => $ticket->id,
                'sla_response_deadline' => $responseDeadline,
                'sla_resolution_deadline' => $resolutionDeadline,
                'current_tier' => 0,
            ]);

            // 4. Log awal
            TicketLog::create([
                'ticket_id' => $ticket->id,
                'admin_id' => null,
                'aksi' => 'dibuat',
                'catatan' => 'Tiket dibuat oleh ' . auth()->user()->username,
            ]);

            // 5. Integrasi Live Monitor (Booking Aset Generik)
            $subUnit = \App\Models\SubUnit::find($request->sub_unit_id);
            if ($subUnit && $subUnit->is_monitored) {
                $assetName = $request->form_data[(string) $subUnit->monitor_asset_field_id] ?? 'Aset Tidak Diketahui';
                $startDT = \Carbon\Carbon::parse($request->form_data[(string) $subUnit->monitor_start_field_id] ?? now());
                $endDT = \Carbon\Carbon::parse($request->form_data[(string) $subUnit->monitor_end_field_id] ?? now()->addHour());

                // Cek bentrok aset
                $bentrok = RoomVehicleBooking::where('nama_aset', $assetName)
                    ->whereNotIn('status', ['reject', 'dibatalkan', 'solve', 'selesai'])
                    ->where('tanggal_mulai', '<', $endDT)
                    ->where('tanggal_selesai', '>', $startDT)
                    ->exists();

                if ($bentrok) {
                    throw ValidationException::withMessages([
                        'form_data.' . $subUnit->monitor_asset_field_id => 'Aset ini sudah dipesan pada jam tersebut.',
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

            return $ticket;
        });

        // 6. Notifikasi WA & In-App (di luar transaksi agar tidak mengganggu DB)
        try {
            $ticket->load('user', 'subUnit');
            // Notifikasi ke User (database + WA jika punya no_wa)
            $ticket->user->notify(new TicketCreatedUserNotification($ticket));
            // Notifikasi ke Admin yang berlangganan Kanal Layanan (Unit)
            $notifiedAdmins = \App\Models\Admin::whereHas('units', function ($query) use ($ticket) {
                $query->where('units.id', $ticket->unit_id);
            })->get();

            if ($notifiedAdmins->isNotEmpty()) {
                Notification::send($notifiedAdmins, new TicketCreatedAdminNotification($ticket));
                Notification::send($notifiedAdmins, new \App\Notifications\BrowserNotification(
                    "Tiket Baru Masuk",
                    "Tiket #{$ticket->ticket_number} baru saja dibuat oleh {$ticket->user->username}",
                    "/admin/tiket/{$ticket->id}"
                ));
            } else {
                // Fallback: kirim WA ke nomor_wa_utama via AnonymousNotifiable
                Notification::send(new \Illuminate\Notifications\AnonymousNotifiable, new TicketCreatedAdminNotification($ticket));
            }
        } catch (\Exception $e) {
            \Log::error("Gagal mengirim notifikasi untuk tiket #{$ticket->id}: " . $e->getMessage());
        }

        return redirect()->route('tiket.riwayat')->with('success', 'Tiket berhasil diajukan!');
    }
}
