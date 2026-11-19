<?php

namespace App\Services;

use App\Models\FormField;
use App\Models\SubUnit;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

class TicketSlaSchedule
{
    public static function inputs(SubUnit $subUnit, iterable $fields, array $formData): ?array
    {
        $byId = [];
        foreach ($fields as $field) {
            if ($field->sub_unit_id === (int) $subUnit->id) {
                $byId[$field->id] = $field;
            }
        }

        $normalize = fn ($value) => rtrim(strtolower(trim((string) $value)), ': ');
        $input = static function (FormField $field) use ($formData, $normalize): array {
            $value = $formData[$field->id] ?? null;
            if ($field->parent_field_id) {
                $parent = $formData[$field->parent_field_id] ?? null;
                $trigger = $normalize($field->trigger_value);
                $visible = false;
                foreach ((array) $parent as $option) {
                    if (is_scalar($option) && $normalize($option) === $trigger) {
                        $visible = true;
                        break;
                    }
                }
                if (! $visible) {
                    $value = null;
                }
            }

            return ['id' => $field->id, 'type' => $field->tipe_field, 'value' => $value];
        };
        $mapped = static function ($id, string $type) use ($byId, $input): ?array {
            $field = $byId[$id] ?? null;

            return $field?->tipe_field === $type ? $input($field) : null;
        };

        $startTime = $endTime = null;
        if ($subUnit->is_monitored) {
            if ($subUnit->monitor_date_field_id) {
                $start = $mapped($subUnit->monitor_date_field_id, 'tanggal');
                $end = $mapped($subUnit->monitor_end_date_field_id, 'tanggal');
                $startTime = $mapped($subUnit->monitor_start_field_id, 'waktu');
                $endTime = $mapped($subUnit->monitor_end_field_id, 'waktu');

                if ($start && ! $subUnit->monitor_end_date_field_id && $startTime && $endTime) {
                    $end = $start;
                    if (! self::filled($startTime) || ! self::filled($endTime)) {
                        $end['value'] = null;
                    }
                }
            } elseif (! $subUnit->monitor_end_date_field_id) {
                $start = $mapped($subUnit->monitor_start_field_id, 'datetime');
                $end = $mapped($subUnit->monitor_end_field_id, 'datetime');
            }

            if (isset($start, $end)) {
                return compact('start', 'end', 'startTime', 'endTime');
            }
        }

        $starts = $ends = [];
        foreach ($byId as $field) {
            if (! in_array($field->tipe_field, ['tanggal', 'datetime'], true)) {
                continue;
            }

            $label = $normalize($field->label);
            if (in_array($label, ['tanggal awal', 'tanggal mulai'], true)) {
                $starts[] = $field;
            } elseif (in_array($label, ['tanggal akhir', 'tanggal selesai'], true)) {
                $ends[] = $field;
            }
        }

        if (count($starts) !== 1 || count($ends) !== 1) {
            return null;
        }

        return ['start' => $input($starts[0]), 'end' => $input($ends[0]), 'startTime' => null, 'endTime' => null];
    }

    /** @return array{start: Carbon, end: Carbon}|null */
    public static function window(?array $inputs): ?array
    {
        if (! self::filled($inputs['start'] ?? null) || ! self::filled($inputs['end'] ?? null)) {
            return null;
        }

        $timezone = new DateTimeZone(config('app.timezone'));
        $start = self::bound($inputs['start'], $inputs['startTime'] ?? null, '00:00:00', $timezone);
        $end = self::bound($inputs['end'], $inputs['endTime'] ?? null, '23:59:59', $timezone);

        if ($end->toDateString() < $start->toDateString()) {
            throw ValidationException::withMessages([
                'form_data.'.$inputs['end']['id'] => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            ]);
        }
        if ($end->lte($start)) {
            throw ValidationException::withMessages([
                'form_data.'.($inputs['endTime']['id'] ?? $inputs['end']['id']) => 'Waktu selesai harus lebih besar dari waktu mulai.',
            ]);
        }

        return compact('start', 'end');
    }

    private static function filled(?array $input): bool
    {
        $value = $input['value'] ?? null;

        return $value !== null && $value !== '';
    }

    private static function bound(array $date, ?array $time, string $defaultTime, DateTimeZone $timezone): Carbon
    {
        if ($date['type'] === 'datetime') {
            return self::parse($date, ['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'], $timezone);
        }

        $day = self::parse($date, ['Y-m-d'], $timezone);
        $clock = self::filled($time) ? self::parse($time, ['H:i', 'H:i:s'], $timezone)->format('H:i:s') : $defaultTime;

        return self::parse([
            'id' => $time['id'] ?? $date['id'],
            'value' => $day->toDateString().' '.$clock,
        ], ['Y-m-d H:i:s'], $timezone);
    }

    private static function parse(array $input, array $formats, DateTimeZone $timezone): Carbon
    {
        $value = $input['value'];
        if (is_string($value) && ! str_contains($value, "\0")) {
            foreach ($formats as $format) {
                $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value, $timezone);
                if ($parsed && $parsed->format($format) === $value) {
                    return Carbon::instance($parsed);
                }
            }
        }

        throw ValidationException::withMessages([
            'form_data.'.$input['id'] => 'Format tanggal atau waktu SLA tidak valid.',
        ]);
    }
}
