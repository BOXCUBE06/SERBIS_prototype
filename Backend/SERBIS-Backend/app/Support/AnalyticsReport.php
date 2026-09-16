<?php

namespace App\Support;

use App\Models\ServiceRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the /admin/analytics payload.
 *
 * Every figure here describes service requests over one window, in the
 * office's own timezone. Four rules run through the whole class:
 *
 * 1. **Manila, not UTC.** created_at is stored UTC (config/app.php pins the
 *    app to UTC and config/database.php pins the connection to +00:00), but
 *    an office day is a Manila day. A weekday/hour grid read straight off the
 *    stored values is eight hours out, which moves the 08:00-17:00 working
 *    day onto 00:00-09:00 and throws anything before 08:00 onto the previous
 *    weekday.
 * 2. **Portable SQL.** The aggregate uses CAST(x AS DATE) and
 *    EXTRACT(HOUR FROM x), which MySQL and Postgres both accept. DAYOFWEEK()
 *    is MySQL-only and EXTRACT(DOW ...) is Postgres-only, so the weekday is
 *    derived in PHP from the bucket's date instead. Manila is a whole number
 *    of hours from UTC and has never observed DST, so shifting a (date, hour)
 *    bucket by the offset is exact rather than approximate.
 * 3. **Nulls are excluded and counted.** Turnaround reads columns that are
 *    only partly backfilled, so every figure carries its own sample size.
 * 4. **Median, not mean.** At these volumes one request left open for a month
 *    drags an average into fiction.
 */
class AnalyticsReport
{
    /**
     * Written out here rather than imported from a controller, matching
     * ConductionRequestController, AmbulanceAvailabilityController,
     * EquipmentBorrowingController and SendReturnDueReminders, which each
     * declare their own. It is a fixed fact about the office, not config that
     * could drift.
     */
    private const OFFICE_TIMEZONE = 'Asia/Manila';

    public const PRESETS = ['month', 'quarter', 'year', 'custom'];

    /** Monday-first, because a duty roster is read that way. */
    private const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public function __construct(
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly string $preset,
        private readonly ?int $barangayId = null,
        private readonly ?int $serviceId = null,
    ) {}

    /**
     * Resolves a preset or an explicit pair of dates into a half-open window
     * [from, to) in UTC, with both boundaries taken in Manila.
     *
     * Half-open on purpose: `to` is the start of the day after the last day
     * shown, so a request filed at 23:30 on the final day is inside the range
     * and nothing lands in two windows at once.
     */
    public static function resolveRange(?string $preset, ?string $from, ?string $to): array
    {
        $today = CarbonImmutable::now(self::OFFICE_TIMEZONE)->startOfDay();
        $preset = in_array($preset, self::PRESETS, true) ? $preset : 'quarter';

        if ($preset === 'custom' && $from !== null && $to !== null) {
            $start = CarbonImmutable::parse($from, self::OFFICE_TIMEZONE)->startOfDay();
            $end = CarbonImmutable::parse($to, self::OFFICE_TIMEZONE)->startOfDay()->addDay();

            // A reversed pair is a client bug, not a reason to return an empty
            // page with no explanation.
            if ($end->lessThanOrEqualTo($start)) {
                $end = $start->addDay();
            }

            // Converted to UTC before they ever reach a query. A Carbon still
            // carrying +08:00 is formatted in its own offset by the query
            // binding, so "2026-09-01 00:00 Manila" would be compared against
            // the UTC column as the literal 2026-09-01 00:00 — eight hours
            // late, silently dropping the requests filed in that gap.
            return [$start->utc(), $end->utc(), 'custom'];
        }

        $start = match ($preset) {
            'month' => $today->startOfMonth(),
            'year' => $today->startOfYear(),
            default => $today->startOfQuarter(),
        };

        return [$start->utc(), $today->addDay()->utc(), $preset === 'custom' ? 'quarter' : $preset];
    }

    public function build(): array
    {
        return [
            'range' => [
                'preset' => $this->preset,
                'from' => $this->from->timezone(self::OFFICE_TIMEZONE)->toDateString(),
                // Inclusive for display: the window is half-open internally,
                // but "to 30 Sep" is what the filter bar said.
                'to' => $this->to->timezone(self::OFFICE_TIMEZONE)->subDay()->toDateString(),
                'timezone' => self::OFFICE_TIMEZONE,
            ],
            'filters' => [
                'barangay_id' => $this->barangayId,
                'service_id' => $this->serviceId,
            ],
            // From the shared helper, so this page and the dashboard cannot
            // report different totals for the same window.
            'totals' => $this->totals(),
            'demand' => $this->demandByWeekdayHour(),
            'volume' => $this->volumeByMonth(),
            'outcomes' => $this->outcomeByMonth(),
            'turnaround' => $this->turnaround(),
            'aging' => $this->aging(),
        ];
    }

    private function totals(): array
    {
        $counts = BarangayRequestCounts::forWindow($this->from, $this->to);

        return [
            'combined' => $counts['total'],
            'walkIn' => $counts['walkIn'],
            'barangayLinked' => $counts['total'] - $counts['walkIn'],
            'serviceRequests' => (int) $this->scoped()->count(),
        ];
    }

    /**
     * Base query for every request-shaped section: the window, plus whichever
     * filters the page has applied.
     *
     * DB::table rather than the model — ServiceRequest eager-loads
     * ambulanceBooking on every get(), which is wasted work against a
     * COUNT/GROUP BY and would hydrate rows nothing here reads.
     */
    private function scoped()
    {
        return DB::table('tbl_service_request')
            ->where('tbl_service_request.created_at', '>=', $this->from)
            ->where('tbl_service_request.created_at', '<', $this->to)
            ->when($this->serviceId, fn ($q) => $q->where('tbl_service_request.service_id', $this->serviceId))
            // A walk-in records no barangay anywhere, so filtering by barangay
            // legitimately excludes it. The inner join is correct here and is
            // not the bug BarangayRequestCounts exists to fix.
            ->when($this->barangayId, fn ($q) => $q
                ->join('tbl_residents', 'tbl_service_request.resident_id', '=', 'tbl_residents.resident_id')
                ->where('tbl_residents.barangay_id', $this->barangayId));
    }

    /**
     * Section 1 — when demand actually arrives, as a 7 x 24 grid.
     *
     * Aggregated by UTC date and UTC hour in the database, then each bucket is
     * shifted into Manila here. The bucket count is bounded by days x 24, so
     * this returns counts rather than rows however large the table gets.
     */
    private function demandByWeekdayHour(): array
    {
        $rows = $this->scoped()
            ->groupByRaw('CAST(tbl_service_request.created_at AS DATE), EXTRACT(HOUR FROM tbl_service_request.created_at)')
            ->selectRaw('CAST(tbl_service_request.created_at AS DATE) as bucket_date, EXTRACT(HOUR FROM tbl_service_request.created_at) as bucket_hour, COUNT(*) as total')
            ->get();

        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        $total = 0;

        foreach ($rows as $row) {
            $utc = CarbonImmutable::parse($row->bucket_date, 'UTC')->setTime((int) $row->bucket_hour, 0);
            $local = $utc->timezone(self::OFFICE_TIMEZONE);

            // dayOfWeekIso is 1 (Mon) to 7 (Sun).
            $grid[$local->dayOfWeekIso - 1][$local->hour] += (int) $row->total;
            $total += (int) $row->total;
        }

        $peak = ['weekday' => null, 'hour' => null, 'count' => 0];

        foreach ($grid as $weekday => $hours) {
            foreach ($hours as $hour => $count) {
                if ($count > $peak['count']) {
                    $peak = ['weekday' => self::WEEKDAYS[$weekday], 'hour' => $hour, 'count' => $count];
                }
            }
        }

        return [
            'weekdays' => self::WEEKDAYS,
            'grid' => $grid,
            'total' => $total,
            'peak' => $peak,
        ];
    }

    /**
     * Section 2 — volume by month, split by service. Stacked bar: months are
     * ordered discrete buckets and the segments sum to a real total.
     */
    private function volumeByMonth(): array
    {
        $rows = $this->scoped()
            ->join('tbl_services', 'tbl_service_request.service_id', '=', 'tbl_services.service_id')
            ->groupByRaw('CAST(tbl_service_request.created_at AS DATE), tbl_services.service_name')
            ->selectRaw('CAST(tbl_service_request.created_at AS DATE) as bucket_date, tbl_services.service_name as label, COUNT(*) as total')
            ->get();

        return $this->stackByMonth($rows);
    }

    /**
     * Section 3 — outcome mix by month. Same shape as volume, keyed on status
     * instead of service, so the page can render both with one component.
     */
    private function outcomeByMonth(): array
    {
        $rows = $this->scoped()
            ->groupByRaw('CAST(tbl_service_request.created_at AS DATE), tbl_service_request.status')
            ->selectRaw('CAST(tbl_service_request.created_at AS DATE) as bucket_date, tbl_service_request.status as label, COUNT(*) as total')
            ->get();

        return $this->stackByMonth($rows);
    }

    /**
     * Rolls day buckets up into Manila months.
     *
     * The rollup happens here rather than in SQL because the month a row
     * belongs to depends on the timezone — a request filed at 07:00 Manila on
     * the 1st is stored as 23:00 UTC on the previous day, and in December that
     * is also the previous year.
     */
    private function stackByMonth($rows): array
    {
        $months = [];
        $labels = [];

        foreach ($rows as $row) {
            $month = CarbonImmutable::parse($row->bucket_date, 'UTC')
                ->timezone(self::OFFICE_TIMEZONE)
                ->format('Y-m');

            $months[$month][$row->label] = ($months[$month][$row->label] ?? 0) + (int) $row->total;
            $labels[$row->label] = true;
        }

        ksort($months);
        $labels = array_keys($labels);
        sort($labels);

        $series = [];
        foreach ($labels as $label) {
            $series[] = [
                'label' => $label,
                'data' => array_map(fn ($counts) => $counts[$label] ?? 0, array_values($months)),
            ];
        }

        $monthLabels = array_map(
            fn ($key) => CarbonImmutable::createFromFormat('Y-m', $key, self::OFFICE_TIMEZONE)->format('M Y'),
            array_keys($months)
        );

        return [
            'labels' => $monthLabels,
            'series' => $series,
            'total' => array_sum(array_map('array_sum', array_values($months))),
        ];
    }

    /**
     * Section 4 — how long the office takes.
     *
     * Reads first_responded_at / resolved_at, which are only partly
     * backfilled, so every figure is paired with the sample it came from and
     * the caller is expected to print it.
     */
    private function turnaround(): array
    {
        $rows = $this->scoped()
            ->select([
                'tbl_service_request.created_at',
                'tbl_service_request.first_responded_at',
                'tbl_service_request.resolved_at',
            ])
            ->get();

        $responseHours = [];
        $resolutionDays = [];

        foreach ($rows as $row) {
            $created = CarbonImmutable::parse($row->created_at, 'UTC');

            if ($row->first_responded_at !== null) {
                $responseHours[] = $created->diffInMinutes(CarbonImmutable::parse($row->first_responded_at, 'UTC')) / 60;
            }

            if ($row->resolved_at !== null) {
                $resolutionDays[] = $created->diffInMinutes(CarbonImmutable::parse($row->resolved_at, 'UTC')) / 1440;
            }
        }

        return [
            'firstResponse' => [
                'medianHours' => $this->median($responseHours),
                'n' => count($responseHours),
            ],
            'resolution' => [
                'medianDays' => $this->median($resolutionDays),
                'n' => count($resolutionDays),
            ],
            'histogram' => $this->resolutionHistogram($resolutionDays),
            'coverage' => [
                'requests' => $rows->count(),
                'withFirstResponse' => count($responseHours),
                'withResolution' => count($resolutionDays),
            ],
        ];
    }

    private function resolutionHistogram(array $days): array
    {
        $buckets = [
            'Same day' => 0,
            '1-3 days' => 0,
            '3-7 days' => 0,
            '7+ days' => 0,
        ];

        foreach ($days as $value) {
            $key = match (true) {
                $value < 1 => 'Same day',
                $value < 3 => '1-3 days',
                $value < 7 => '3-7 days',
                default => '7+ days',
            };

            $buckets[$key]++;
        }

        return [
            'labels' => array_keys($buckets),
            'data' => array_values($buckets),
            'n' => count($days),
        ];
    }

    /**
     * Section 5 — how long the requests that are still open have been open.
     *
     * Needs no backfilled column: it reads created_at and the current status,
     * so it is trustworthy for every row from the day it ships. "Open" is
     * anything not in a terminal status.
     */
    private function aging(): array
    {
        // Deliberately ignores the window. An request filed last quarter that
        // is still open is exactly what this section exists to surface, and
        // scoping it to the range would hide the oldest ones — the only ones
        // that matter here.
        $rows = DB::table('tbl_service_request')
            // Table-qualified: the barangay filter below joins tbl_residents,
            // which also has a `status` column, and an unqualified name there
            // is a 1052 rather than a wrong answer.
            ->whereNotIn('tbl_service_request.status', ServiceRequest::TERMINAL_STATUSES)
            ->when($this->serviceId, fn ($q) => $q->where('tbl_service_request.service_id', $this->serviceId))
            ->when($this->barangayId, fn ($q) => $q
                ->join('tbl_residents', 'tbl_service_request.resident_id', '=', 'tbl_residents.resident_id')
                ->where('tbl_residents.barangay_id', $this->barangayId))
            ->select('tbl_service_request.created_at')
            ->get();

        $buckets = [
            'Under a day' => 0,
            '1-3 days' => 0,
            '3-7 days' => 0,
            '7+ days' => 0,
        ];

        $now = CarbonImmutable::now('UTC');
        $oldestDays = 0;

        foreach ($rows as $row) {
            $age = CarbonImmutable::parse($row->created_at, 'UTC')->diffInMinutes($now) / 1440;
            $oldestDays = max($oldestDays, $age);

            $key = match (true) {
                $age < 1 => 'Under a day',
                $age < 3 => '1-3 days',
                $age < 7 => '3-7 days',
                default => '7+ days',
            };

            $buckets[$key]++;
        }

        return [
            'labels' => array_keys($buckets),
            'data' => array_values($buckets),
            'total' => $rows->count(),
            'oldestDays' => (int) floor($oldestDays),
        ];
    }

    /**
     * Median rather than mean throughout: these are small samples, and one
     * request left open for a month would drag an average somewhere no real
     * request sits. Returns null on an empty sample so the caller prints
     * "no data" instead of a confident zero.
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        $median = $count % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];

        return round($median, 1);
    }
}
