<?php

namespace App\Http\Requests;

use App\Models\FormField;
use App\Models\SubUnit;
use App\Services\TicketFormEditor;
use App\Services\TicketSlaSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

/**
 * Validates a whole bulk submission up front so the writer never starts a
 * transaction it will abandon halfway through.
 */
class BulkStoreTicketRequest extends FormRequest
{
    public const MAX_TICKETS = 5;

    /** @var array<int, array{data: array<string, mixed>, subUnit: SubUnit, window: array|null, formDeadline?: \Carbon\Carbon|null}> */
    private array $rows = [];

    public function isAdminBulk(): bool
    {
        return $this->routeIs('admin.*');
    }

    protected function getRedirectUrl(): string
    {
        return $this->isAdminBulk()
            ? route('admin.tickets.bulk.create')
            : route('tiket.bulk.create');
    }

    public function authorize(): bool
    {
        return $this->isAdminBulk() ? auth('admin')->check() : auth('web')->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $priorityRules = $this->isAdminBulk()
            ? ['required', 'string', new In(TicketFormEditor::PRIORITIES)]
            : ['nullable', 'string'];

        return [
            'tickets' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_TICKETS],
            'tickets.*' => ['required', 'array:unit_id,sub_unit_id,priority,form_data,attachment,attachment_target'],
            'tickets.*.unit_id' => ['required', 'integer', 'min:1'],
            'tickets.*.sub_unit_id' => ['required', 'integer', 'min:1'],
            'tickets.*.priority' => $priorityRules,
            'tickets.*.form_data' => ['present', 'array'],
            'tickets.*.attachment' => ['nullable', 'file', 'max:2048'],
            'tickets.*.attachment_target' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tickets.required' => 'Ajukan minimal 1 tiket.',
            'tickets.array' => 'Ajukan minimal 1 tiket.',
            'tickets.list' => 'Ajukan minimal 1 tiket.',
            'tickets.min' => 'Ajukan minimal 1 tiket.',
            'tickets.max' => 'Maksimal '.self::MAX_TICKETS.' tiket dalam satu kali pengajuan.',
            'tickets.*.required' => 'Baris tiket tidak valid.',
            'tickets.*.array' => 'Baris tiket tidak valid.',
            'tickets.*.unit_id.required' => 'Kanal layanan wajib dipilih.',
            'tickets.*.unit_id.integer' => 'Kanal layanan tidak valid.',
            'tickets.*.sub_unit_id.required' => 'Jenis layanan wajib dipilih.',
            'tickets.*.sub_unit_id.integer' => 'Jenis layanan tidak valid.',
            'tickets.*.priority.required' => 'Prioritas wajib dipilih.',
            'tickets.*.priority.in' => 'Prioritas tidak valid.',
            'tickets.*.form_data.required' => 'Isian formulir tidak ditemukan.',
            'tickets.*.form_data.array' => 'Isian formulir tidak valid.',
            'tickets.*.attachment.file' => 'Lampiran tidak valid.',
            'tickets.*.attachment.max' => 'Ukuran lampiran maksimal 2 MB per tiket.',
            'tickets.*.attachment_target.max' => 'Tujuan lampiran tidak valid.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $tickets = $this->input('tickets');

        if (! is_array($tickets)) {
            return;
        }

        foreach ($tickets as $index => $row) {
            if (! is_array($row) || ! array_key_exists('form_data', $row) || ! is_string($row['form_data'])) {
                continue;
            }

            $decoded = json_decode($row['form_data'], true);
            if (is_array($decoded)) {
                $tickets[$index]['form_data'] = $decoded;
            }
            // Invalid JSON stays a string so the `array` rule rejects just that row.
        }

        $this->merge(['tickets' => $tickets]);
    }

    /**
     * @return array<int, array{data: array<string, mixed>, subUnit: SubUnit, window: array|null}>
     */
    public function validatedRows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (LaravelValidator $validator): void {
            $this->validateRows($validator);
        }];
    }

    private function validateRows(LaravelValidator $validator): void
    {
        $tickets = $this->input('tickets');

        if (! is_array($tickets) || ! array_is_list($tickets) || count($tickets) < 1 || count($tickets) > self::MAX_TICKETS) {
            return;
        }

        $errors = $validator->errors();
        $subUnits = [];
        $this->rows = [];

        foreach ($tickets as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $prefix = "tickets.{$index}.";

            if ($errors->has("tickets.{$index}")) {
                continue;
            }

            $requiredKeys = $this->isAdminBulk()
                ? ['unit_id', 'sub_unit_id', 'priority', 'form_data']
                : ['unit_id', 'sub_unit_id', 'form_data'];

            foreach ($requiredKeys as $key) {
                if ($errors->has($prefix.$key)) {
                    continue 2;
                }
            }

            $unitId = (int) $row['unit_id'];
            $subUnitId = (int) $row['sub_unit_id'];

            if (! array_key_exists($subUnitId, $subUnits)) {
                $subUnits[$subUnitId] = SubUnit::aktif()->with(['unit', 'formFields'])->find($subUnitId);
            }

            $subUnit = $subUnits[$subUnitId];
            $unit = $subUnit?->unit;

            if (! $subUnit || ! $unit || (int) $unit->id !== $unitId || ! ($unit->aktif === null || (bool) $unit->aktif)) {
                $errors->add($prefix.'sub_unit_id', 'Pilih jenis layanan aktif dari kanal ini.');
                continue;
            }

            $formData = $row['form_data'];
            $normalized = $this->normalizeFormData($validator, $index, $subUnit, $formData);

            if ($normalized === null) {
                continue;
            }

            $attachments = $this->normalizeAttachment($validator, $index, $subUnit, $normalized);

            if ($attachments === false) {
                continue;
            }

            try {
                $window = TicketSlaSchedule::window(
                    TicketSlaSchedule::inputs($subUnit, $subUnit->formFields, $normalized)
                );
                $formDeadline = TicketSlaSchedule::extensionDeadline(
                    TicketSlaSchedule::extensionInput($subUnit, $subUnit->formFields, $normalized)
                );
            } catch (ValidationException $e) {
                foreach ($e->errors() as $key => $messages) {
                    $errors->add($prefix.$key, $messages[0]);
                }
                continue;
            }

            $this->rows[] = [
                'data' => [
                    'unit_id' => $unitId,
                    'sub_unit_id' => $subUnitId,
                    'priority' => $this->isAdminBulk() ? $row['priority'] : null,
                    'form_data' => $normalized,
                    'attachments' => $attachments['attachments'],
                    'general_attachments' => $attachments['general_attachments'],
                ],
                'subUnit' => $subUnit,
                'window' => $window,
                'formDeadline' => $formDeadline,
            ];
        }
    }

    /**
     * @param  array<array-key, mixed>  $formData
     * @return array<string, mixed>|null  null when this row already has errors
     */
    private function normalizeFormData(
        LaravelValidator $validator,
        int $index,
        SubUnit $subUnit,
        array $formData
    ): ?array {
        $errors = $validator->errors();
        $prefix = "tickets.{$index}.";
        $fields = [];

        foreach ($subUnit->formFields as $field) {
            $fields[(string) $field->id] = $field;
        }

        foreach ($formData as $key => $value) {
            if (! array_key_exists((string) $key, $fields)) {
                $errors->add($prefix.'form_data', 'Isian tidak sesuai dengan jenis layanan.');

                return null;
            }
        }

        $failed = false;
        $visible = [];

        foreach ($fields as $key => $field) {
            $isVisible = $this->fieldVisible($field, $formData);
            $visible[$key] = $isVisible;

            if (! $isVisible || $field->isUpload() || $field->tipe_field === 'info_peraturan') {
                continue;
            }

            $value = array_key_exists($key, $formData) ? $formData[$key] : null;

            if ($field->wajib && $this->isEmptyValue($value)) {
                $errors->add($prefix."form_data.{$key}", "Field \"{$field->label}\" wajib diisi.");
                $failed = true;
                continue;
            }

            if ($this->isEmptyValue($value)) {
                continue;
            }

            if (! $this->valueMatchesType($field, $value)) {
                $errors->add($prefix."form_data.{$key}", "Field \"{$field->label}\" tidak valid.");
                $failed = true;
            }
        }

        if ($failed) {
            return null;
        }

        $normalized = [];

        foreach ($formData as $key => $value) {
            $field = $fields[(string) $key];

            if (! $visible[(string) $key] || $field->isUpload() || $field->tipe_field === 'info_peraturan') {
                continue;
            }

            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return array{attachments: array<int, array<int, UploadedFile>>, general_attachments: array<int, UploadedFile>}|false
     */
    private function normalizeAttachment(
        LaravelValidator $validator,
        int $index,
        SubUnit $subUnit,
        array $formData
    ): array|false {
        $errors = $validator->errors();
        $prefix = "tickets.{$index}.";
        $file = $this->file($prefix.'attachment');
        $target = $this->input($prefix.'attachment_target');
        $target = is_string($target) ? trim($target) : null;

        if ($target === '') {
            $target = null;
        }

        if ($errors->has($prefix.'attachment')) {
            return false;
        }

        $visibleUploads = [];

        foreach ($subUnit->formFields as $field) {
            if ($field->isUpload() && $this->fieldVisible($field, $formData)) {
                $visibleUploads[(string) $field->id] = $field;
            }
        }

        $requiredUploads = array_filter($visibleUploads, fn (FormField $field) => $field->wajib);

        if (count($requiredUploads) > 1) {
            $errors->add($prefix.'attachment', 'Layanan ini memerlukan lebih dari satu lampiran. Ajukan tiket satu per satu.');

            return false;
        }

        if (! $file instanceof UploadedFile) {
            if ($target !== null) {
                $errors->add($prefix.'attachment', 'Lampiran tidak diterima. Pilih ulang file.');

                return false;
            }

            if (count($requiredUploads) === 1) {
                $field = reset($requiredUploads);
                $errors->add($prefix.'attachment', "Lampiran wajib diisi untuk field \"{$field->label}\".");

                return false;
            }

            return ['attachments' => [], 'general_attachments' => []];
        }

        $field = null;

        if ($target !== null && $target !== 'general') {
            if (! isset($visibleUploads[$target])) {
                $errors->add($prefix.'attachment', 'Tujuan lampiran tidak valid.');

                return false;
            }

            $field = $visibleUploads[$target];
        }

        $required = count($requiredUploads) === 1 ? reset($requiredUploads) : null;

        // A required upload must receive the file; never downgrade `general` silently.
        if ($required && ($field === null || (string) $required->id !== (string) $field->id)) {
            $errors->add($prefix.'attachment', "Lampiran wajib diisi untuk field \"{$required->label}\".");

            return false;
        }

        $type = $field ? 'field' : 'general';

        $mime = match (true) {
            $type === 'general' => 'mimes:jpg,jpeg,png,pdf',
            $field->tipe_field === 'upload_gambar' => 'mimes:jpg,jpeg,png',
            default => 'mimes:jpg,jpeg,png,pdf,doc,docx',
        };

        $probe = ValidatorFacade::make(
            ['file' => $file],
            ['file' => ['file', $mime]],
            ['file.mimes' => 'Tipe lampiran tidak diizinkan.']
        );

        if ($probe->fails()) {
            $errors->add($prefix.'attachment', $probe->errors()->first('file'));

            return false;
        }

        if ($type === 'general') {
            return ['attachments' => [], 'general_attachments' => [$file]];
        }

        return [
            'attachments' => [(int) $field->id => [$file]],
            'general_attachments' => [],
        ];
    }

    /**
     * Mirrors DynamicField's visibility rule: the immediate parent value must match the trigger.
     *
     * @param  array<array-key, mixed>  $formData
     */
    private function fieldVisible(FormField $field, array $formData): bool
    {
        if (! $field->parent_field_id) {
            return true;
        }

        $parent = $formData[(string) $field->parent_field_id] ?? null;

        if ($parent === null) {
            return false;
        }

        $trigger = $this->normalizeTrigger($field->trigger_value);

        if (is_array($parent)) {
            return array_is_list($parent) && collect($parent)->contains(
                fn ($value) => is_scalar($value) && $this->normalizeTrigger($value) === $trigger
            );
        }

        return is_scalar($parent) && $this->normalizeTrigger($parent) === $trigger;
    }

    private function normalizeTrigger(mixed $value): string
    {
        return rtrim(strtolower(trim((string) $value)), ': ');
    }

    private function isEmptyValue(mixed $value): bool
    {
        return match (true) {
            $value === null => true,
            is_string($value) => trim($value) === '',
            is_array($value) => $value === [],
            is_bool($value) => $value === false,
            default => false,
        };
    }

    private function valueMatchesType(FormField $field, mixed $value): bool
    {
        return match ($field->tipe_field) {
            'teks_pendek', 'teks_panjang' => is_string($value),
            'angka' => is_numeric($value),
            'nominal_rp' => is_string($value)
                ? ctype_digit($value)
                : is_int($value) && $value >= 0,
            'tanggal' => is_string($value)
                && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
                && checkdate((int) $m[2], (int) $m[3], (int) $m[1]),
            'waktu' => is_string($value) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value) === 1,
            'datetime' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $value) === 1,
            'checkbox' => is_bool($value),
            'dropdown', 'radio' => is_string($value) && in_array($value, (array) $field->opsi, true),
            'multi_pilih' => is_array($value)
                && array_is_list($value)
                && collect($value)->every(fn ($option) => is_string($option) && in_array($option, (array) $field->opsi, true)),
            default => is_scalar($value),
        };
    }
}
