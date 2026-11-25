<?php

namespace App\Services\Ai\Tools;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class PeriodResolver
{
    public const VALID_PERIODS = [
        'today',
        '7d',
        '30d',
        'this_month',
        'last_month',
        'this_year',
        'all',
    ];

    /**
     * Resolve a period preset or explicit date_from/date_to range in Asia/Jakarta timezone.
     *
     * @return array{
     *     key: string,
     *     label: string,
     *     start: ?Carbon,
     *     end: ?Carbon,
     *     date_from: ?string,
     *     date_to: ?string
     * }
     */
    public static function resolve(
        ?string $period = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $defaultPeriod = '30d'
    ): array {
        $tz = 'Asia/Jakarta';
        $now = Carbon::now($tz);

        $parsedFrom = self::parseDate($dateFrom, $tz)?->startOfDay();
        $parsedTo = self::parseDate($dateTo, $tz)?->endOfDay();

        if ($parsedFrom !== null || $parsedTo !== null) {
            if ($parsedFrom !== null && $parsedTo !== null && $parsedFrom->gt($parsedTo)) {
                [$parsedFrom, $parsedTo] = [$parsedTo->copy()->startOfDay(), $parsedFrom->copy()->endOfDay()];
            }

            $fromStr = $parsedFrom?->format('Y-m-d');
            $toStr = $parsedTo?->format('Y-m-d');

            $label = match (true) {
                $fromStr !== null && $toStr !== null => $parsedFrom->format('d M Y') . ' - ' . $parsedTo->format('d M Y'),
                $fromStr !== null => 'Mulai ' . $parsedFrom->format('d M Y'),
                default => 'Sampai ' . $parsedTo->format('d M Y'),
            };

            return [
                'key' => 'custom',
                'label' => $label,
                'start' => $parsedFrom,
                'end' => $parsedTo,
                'date_from' => $fromStr,
                'date_to' => $toStr,
            ];
        }

        $key = strtolower(trim((string) ($period ?: $defaultPeriod)));
        if (! in_array($key, self::VALID_PERIODS, true)) {
            $key = $defaultPeriod;
        }

        return match ($key) {
            'today' => [
                'key' => 'today',
                'label' => 'Hari Ini (' . $now->format('d M Y') . ')',
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'date_from' => $now->format('Y-m-d'),
                'date_to' => $now->format('Y-m-d'),
            ],
            '7d' => [
                'key' => '7d',
                'label' => '7 Hari Terakhir',
                'start' => $now->copy()->subDays(6)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'date_from' => $now->copy()->subDays(6)->format('Y-m-d'),
                'date_to' => $now->format('Y-m-d'),
            ],
            '30d' => [
                'key' => '30d',
                'label' => '30 Hari Terakhir',
                'start' => $now->copy()->subDays(29)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'date_from' => $now->copy()->subDays(29)->format('Y-m-d'),
                'date_to' => $now->format('Y-m-d'),
            ],
            'this_month' => [
                'key' => 'this_month',
                'label' => 'Bulan Ini (' . $now->copy()->locale('id')->translatedFormat('F Y') . ')',
                'start' => $now->copy()->startOfMonth()->startOfDay(),
                'end' => $now->copy()->endOfMonth()->endOfDay(),
                'date_from' => $now->copy()->startOfMonth()->format('Y-m-d'),
                'date_to' => $now->copy()->endOfMonth()->format('Y-m-d'),
            ],
            'last_month' => (function () use ($now): array {
                $prev = $now->copy()->subMonthNoOverflow();

                return [
                    'key' => 'last_month',
                    'label' => 'Bulan Lalu (' . $prev->copy()->locale('id')->translatedFormat('F Y') . ')',
                    'start' => $prev->copy()->startOfMonth()->startOfDay(),
                    'end' => $prev->copy()->endOfMonth()->endOfDay(),
                    'date_from' => $prev->copy()->startOfMonth()->format('Y-m-d'),
                    'date_to' => $prev->copy()->endOfMonth()->format('Y-m-d'),
                ];
            })(),
            'this_year' => [
                'key' => 'this_year',
                'label' => 'Tahun ' . $now->year,
                'start' => $now->copy()->startOfYear()->startOfDay(),
                'end' => $now->copy()->endOfYear()->endOfDay(),
                'date_from' => $now->copy()->startOfYear()->format('Y-m-d'),
                'date_to' => $now->copy()->endOfYear()->format('Y-m-d'),
            ],
            default => [
                'key' => 'all',
                'label' => 'Semua Periode',
                'start' => null,
                'end' => null,
                'date_from' => null,
                'date_to' => null,
            ],
        };
    }

    /**
     * Apply the resolved range onto an Eloquent query using portable whereBetween/comparisons.
     *
     * @param  array{start: ?Carbon, end: ?Carbon}  $resolved
     */
    public static function applyToQuery(Builder $query, string $column, array $resolved): Builder
    {
        $appTz = (string) config('app.timezone', 'Asia/Jakarta');
        $start = $resolved['start']?->copy()->timezone($appTz);
        $end = $resolved['end']?->copy()->timezone($appTz);

        if ($start !== null && $end !== null) {
            return $query->whereBetween($column, [$start, $end]);
        }

        if ($start !== null) {
            return $query->where($column, '>=', $start);
        }

        if ($end !== null) {
            return $query->where($column, '<=', $end);
        }

        return $query;
    }

    private static function parseDate(?string $raw, string $tz): ?Carbon
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
