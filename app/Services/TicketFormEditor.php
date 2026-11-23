<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\FormField;
use App\Models\Ticket;
use App\Models\TicketEditHistory;
use App\Models\TicketLog;
use App\Notifications\TicketFormEditedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class TicketFormEditor
{
    /**
     * Status tiket yang formulirnya masih boleh diedit admin.
     */
    public const EDITABLE_STATUSES = ['open', 'on_proses', 'pending', 'need_revision'];

    public const PRIORITIES = ['Rendah', 'Sedang', 'Tinggi', 'Urgen'];

    /**
     * Terapkan perubahan formulir tiket oleh admin.
     *
     * @param  array<string, mixed>  $formData
     * @return TicketEditHistory|null  null jika tidak ada yang berubah
     *
     * @throws ValidationException
     */
    public static function apply(Ticket $ticket, array $formData, ?string $priority, string $reason, Admin $editor): ?TicketEditHistory
    {
        return DB::transaction(function () use ($ticket, $formData, $priority, $reason, $editor) {
            $lockedTicket = Ticket::lockForUpdate()->findOrFail($ticket->id);

            return self::applyLocked($lockedTicket, $formData, $priority, $reason, $editor);
        });
    }

    private static function applyLocked(Ticket $ticket, array $formData, ?string $priority, string $reason, Admin $editor): ?TicketEditHistory
    {
        if (! in_array(strtolower((string) $ticket->status), self::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'form_data' => 'Formulir tiket berstatus '.$ticket->status.' tidak dapat diedit.',
            ]);
        }

        $fields = FormField::where('sub_unit_id', $ticket->sub_unit_id)
            ->orderBy('urutan')
            ->get()
            ->keyBy(fn ($f) => (string) $f->id);

        $old = is_array($ticket->form_data) ? $ticket->form_data : [];
        $new = $old;
        $changes = [];

        foreach ($fields as $key => $field) {
            if ($field->isUpload() || $field->tipe_field === 'info_peraturan') {
                continue;
            }

            // Field bersyarat: hanya divalidasi saat pemicunya cocok (sama seperti wizard pengajuan).
            if ($field->parent_field_id) {
                $parentKey = (string) $field->parent_field_id;
                $parentValue = $new[$parentKey] ?? $old[$parentKey] ?? null;
                $normalize = fn ($v) => rtrim(strtolower(trim((string) $v)), ': ');
                $trigger = $normalize($field->trigger_value);
                $matched = is_array($parentValue)
                    ? collect($parentValue)->contains(fn ($v) => $normalize($v) === $trigger)
                    : $normalize($parentValue) === $trigger;

                if (! $matched) {
                    continue;
                }
            }

            $incoming = array_key_exists($key, $formData) ? $formData[$key] : ($old[$key] ?? null);
            if (is_string($incoming)) {
                $incoming = trim($incoming);
            }

            if ($field->wajib && ($incoming === null || $incoming === '' || $incoming === [])) {
                throw ValidationException::withMessages([
                    'form_data.'.$key => "Field \"{$field->label}\" wajib diisi.",
                ]);
            }

            if (self::normalize($incoming) === self::normalize($old[$key] ?? null)) {
                continue;
            }

            $new[$key] = $incoming;
            $changes[$key] = [
                'label' => $field->label,
                'old' => $old[$key] ?? null,
                'new' => $incoming,
            ];
        }

        $oldPriority = $ticket->priority;
        $newPriority = $priority ?: $oldPriority;
        if ($newPriority !== null && ! in_array($newPriority, self::PRIORITIES, true)) {
            throw ValidationException::withMessages([
                'priority' => 'Prioritas tidak valid.',
            ]);
        }
        if ($newPriority !== $oldPriority) {
            $changes['priority'] = [
                'label' => 'Prioritas',
                'old' => $oldPriority,
                'new' => $newPriority,
            ];
        }

        if (empty($changes)) {
            return null;
        }

        $scheduleChanged = false;
        $window = null;
        $formDateChanged = false;
        $formDeadline = null;
        if ($old !== $new && ($subUnit = $ticket->subUnit)) {
            $oldInputs = TicketSlaSchedule::inputs($subUnit, $fields, $old);
            $newInputs = TicketSlaSchedule::inputs($subUnit, $fields, $new);
            $scheduleChanged = $oldInputs !== $newInputs;
            if ($scheduleChanged) {
                $window = TicketSlaSchedule::window($newInputs);
            }

            $oldExtension = TicketSlaSchedule::extensionInput($subUnit, $fields, $old);
            $newExtension = TicketSlaSchedule::extensionInput($subUnit, $fields, $new);
            $formDateChanged = $oldExtension !== $newExtension;
            if ($formDateChanged) {
                $formDeadline = TicketSlaSchedule::extensionDeadline($newExtension);
            }
        }

        $ticket->form_data = $new;
        $ticket->priority = $newPriority;
        $ticket->save();

        $priorityChanged = $newPriority !== $oldPriority;
        if ($scheduleChanged || $formDateChanged || $priorityChanged) {
            $sla = $ticket->slaTracking()->lockForUpdate()->first();
            if ($sla) {
                $sla->setRelation('ticket', $ticket);
                $calculator = new SlaCalculator;
                if ($scheduleChanged && $sla->resolved_at === null) {
                    $calculator->updateScheduledResolution($sla, $window);
                }
                if ($formDateChanged && $sla->resolved_at === null) {
                    $calculator->applyFormDeadline($sla, $formDeadline);
                }
                if ($priorityChanged) {
                    $calculator->updatePriorityDeadlines($sla);
                }
            }
        }

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $editor->id,
            'aksi' => 'edit_form',
            'catatan' => 'Formulir tiket diperbarui: '.implode(', ', array_map(
                fn ($c) => $c['label'],
                $changes
            )).'. Alasan: '.$reason,
            'timestamp' => now(),
        ]);

        $history = $ticket->editHistories()->create([
            'edited_by' => $editor->id,
            'edit_reason' => $reason,
            'changes' => $changes,
        ]);
        DB::afterCommit(fn () => self::notify($ticket, $history, $editor));

        return $history;
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_values(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $value));
        }

        return is_string($value) ? trim($value) : $value;
    }

    private static function notify(Ticket $ticket, TicketEditHistory $history, Admin $editor): void
    {
        try {
            $ticket->loadMissing('subUnit');

            $admins = Admin::query()
                ->where('id', '!=', $editor->id)
                ->where(function ($q) use ($ticket) {
                    $q->whereHas('units', fn ($u) => $u->where('units.id', $ticket->unit_id))
                        ->orWhereHas('subUnits', fn ($s) => $s->where('sub_units.id', $ticket->sub_unit_id))
                        ->orWhereHas('roles', fn ($r) => $r->whereIn('name', ['superadmin', 'Super Admin', 'super_admin']));
                })
                ->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new TicketFormEditedNotification($ticket, $history, $editor));
            }

            if ($ticket->user) {
                $ticket->user->notify(new TicketFormEditedNotification($ticket, $history, $editor));
            }
        } catch (\Throwable $e) {
            Log::error("Gagal mengirim notifikasi edit form tiket #{$ticket->id}: ".$e->getMessage());
        }
    }
}
