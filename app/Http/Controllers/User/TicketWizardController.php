<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\FormField;
use App\Models\OrgDivisi;
use App\Models\OrgJabatan;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use App\Services\SlaCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TicketWizardController extends Controller
{
    public function create()
    {
        return Inertia::render('User/Tiket/Wizard', [
            'divisiList' => OrgDivisi::orderBy('nama_divisi')->get(),
            'jabatanList' => OrgJabatan::orderBy('urutan')->orderBy('id')->get(),
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
            'divisi_id' => 'required|exists:org_divisi,id',
            'org_unit_id' => 'required|exists:org_unit,id',
            'jabatan_id' => 'required|exists:org_jabatan,id',
            'unit_id' => 'required|exists:units,id',
            'sub_unit_id' => 'required|exists:sub_units,id',
            'form_data' => 'required|array',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240', // max 10MB per file
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

        DB::transaction(function () use ($request, $formFields, $slaCalculator) {
            // 1. Buat tiket
            $ticket = Ticket::create([
                'user_id' => auth()->id(),
                'divisi_id' => $request->divisi_id,
                'org_unit_id' => $request->org_unit_id,
                'jabatan_id' => $request->jabatan_id,
                'unit_id' => $request->unit_id,
                'sub_unit_id' => $request->sub_unit_id,
                'form_data' => $request->form_data,
                'status' => 'open',
            ]);

            // 2. Simpan attachments
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $fieldId => $file) {
                    $path = $file->store("ticket-attachments/{$ticket->id}", 'public');

                    $field = $formFields->firstWhere('id', $fieldId);

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
        });

        return redirect()->route('tiket.riwayat')->with('success', 'Tiket berhasil diajukan!');
    }
}
