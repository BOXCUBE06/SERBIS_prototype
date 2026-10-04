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
 * 2. **Portable SQL, one exception.** The weekday grid uses CAST(x AS DATE)
 *    and EXTRACT(HOUR FROM x), which MySQL and Postgres both accept. DAYOFWEEK()
 *    is MySQL-only and EXTRACT(DOW ...) is Postgres-only, so the weekday is
 *    derived in PHP from the bucket's date instead. Manila is a whole number
 *    of hours from UTC and has never observed DST, so shifting a (date, hour)
 *    bucket by the offset is exact rather than approximate. The month rollup
 *    shifts in SQL with DATE_FORMAT, which is MySQL-only (production's engine).
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

    /** Manila's fixed offset from UTC (no DST), for the SQL month shift. */
    private const OFFICE_UTC_OFFSET_HOURS = 8;

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
            'totals' => $this->totals(),
            'demand' => $this->demandByWeekdayHour(),
            'volume' => $this->volumeByMonth(),
            'outcomes' => $this->outcomeByMonth(),
            'turnaround' => $this->turnaround(),
            'aging' => $this->aging(),
            'equipmentUtilization' => $this->equipmentUtilization(),
            'loans' => $this->loanTurnaround(),
            'vehicleTrips' => $this->mostUsedVehicles(),
            'barangayCoverage' => $this->barangayResidentsVsRequests(),
            'adoption' => $this->appAdoptionByMonth(),
        ];
    }

    private function totals(): array
    {
        $requests = (int) $this->scoped()->count();

        // A walk-in is a request with no barangay, so a barangay filter leaves none.
        $walkIn = (int) $this->scoped()->whereNull('tbl_service_request.barangay_id')->count();

        // Loans carry no service, so a service filter leaves none.
        $loans = $this->serviceId ? 0 : (int) $this->borrowingsScoped()->count();

        return [
            'combined' => $requests + $loans,
            'walkIn' => $walkIn,
            'barangayLinked' => $requests + $loans - $walkIn,
            'serviceRequests' => $requests,
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
            // The barangay the request was filed under, not the resident's
            // current one. A walk-in has none, so the filter excludes it.
            ->when($this->barangayId, fn ($q) => $q
                ->where('tbl_service_request.barangay_id', $this->barangayId));
    }

    /** Monday-first day labels, paired with the four-block day split below. */
    private const TIME_BLOCKS = ['Morning', 'Afternoon', 'Evening', 'Late night'];

    /**
     * Section 1 — when demand actually arrives: which day, and which quarter
     * of the day, in Manila.
     *
     * Aggregated by UTC date and UTC hour in the database, then each bucket is
     * shifted into Manila and folded into a weekday total and a time-of-day
     * total here. The bucket count is bounded by days x 24, so this returns
     * counts rather than rows however large the table gets.
     */
    private function demandByWeekdayHour(): array
    {
        $rows = $this->scoped()
            ->groupByRaw('CAST(tbl_service_request.created_at AS DATE), EXTRACT(HOUR FROM tbl_service_request.created_at)')
            ->selectRaw('CAST(tbl_service_request.created_at AS DATE) as bucket_date, EXTRACT(HOUR FROM tbl_service_request.created_at) as bucket_hour, COUNT(*) as total')
            ->get();

        $dayTotals = array_fill_keys(self::WEEKDAYS, 0);
        $blockTotals = array_fill_keys(self::TIME_BLOCKS, 0);
        $crossTab = [];
        $total = 0;

        foreach ($rows as $row) {
            $utc = CarbonImmutable::parse($row->bucket_date, 'UTC')->setTime((int) $row->bucket_hour, 0);
            $local = $utc->timezone(self::OFFICE_TIMEZONE);
            $count = (int) $row->total;

            $weekday = self::WEEKDAYS[$local->dayOfWeekIso - 1];
            $block = self::timeBlockFor($local->hour);

            $dayTotals[$weekday] += $count;
            $blockTotals[$block] += $count;
            $crossTab[$weekday][$block] = ($crossTab[$weekday][$block] ?? 0) + $count;
            $total += $count;
        }

        $peak = ['weekday' => null, 'block' => null, 'count' => 0];

        foreach ($crossTab as $weekday => $blocks) {
            foreach ($blocks as $block => $count) {
                if ($count > $peak['count']) {
                    $peak = ['weekday' => $weekday, 'block' => $block, 'count' => $count];
                }
            }
        }

        return [
            'days' => ['labels' => self::WEEKDAYS, 'data' => array_values($dayTotals)],
            'timeOfDay' => ['labels' => self::TIME_BLOCKS, 'data' => array_values($blockTotals)],
            'total' => $total,
            'peak' => $peak,
        ];
    }

    /**
     * Morning 6 AM-12 PM, Afternoon 12-6 PM, Evening 6 PM-12 AM, Late night
     * 12-6 AM — the four-block split the Analytics page shows instead of a
     * raw hour. $hour is the Manila-local hour, 0-23.
     */
    private static function timeBlockFor(int $hour): string
    {
        return match (true) {
            $hour >= 6 && $hour < 12 => 'Morning',
            $hour >= 12 && $hour < 18 => 'Afternoon',
            $hour >= 18 => 'Evening',
            default => 'Late night',
        };
    }

    /** Manila calendar month ('YYYY-MM') of a UTC column, computed in SQL. */
    private static function manilaMonth(string $column): string
    {
        return "DATE_FORMAT({$column} + INTERVAL ".self::OFFICE_UTC_OFFSET_HOURS." HOUR, '%Y-%m')";
    }

    /**
     * Section 2 — volume by month, split by service. Stacked bar: months are
     * ordered discrete buckets and the segments sum to a real total. A request
     * with no service (an "Others" request) is its own segment, not dropped.
     */
    private function volumeByMonth(): array
    {
        $month = self::manilaMonth('tbl_service_request.created_at');
        $label = "COALESCE(tbl_services.service_name, 'Others')";

        $rows = $this->scoped()
            ->leftJoin('tbl_services', 'tbl_service_request.service_id', '=', 'tbl_services.service_id')
            ->groupByRaw("{$month}, {$label}")
            ->selectRaw("{$month} as month, {$label} as label, COUNT(*) as total")
            ->get();

        return $this->stackByMonth($rows);
    }

    /**
     * Section 3 — outcome mix by month. Same shape as volume, keyed on status
     * instead of service, so the page can render both with one component.
     */
    private function outcomeByMonth(): array
    {
        $month = self::manilaMonth('tbl_service_request.created_at');

        $rows = $this->scoped()
            ->groupByRaw("{$month}, tbl_service_request.status")
            ->selectRaw("{$month} as month, tbl_service_request.status as label, COUNT(*) as total")
            ->get();

        return $this->stackByMonth($rows);
    }

    /**
     * Stacks rows of (month, label, total) into one series per label.
     *
     * The month is already the Manila one: it is shifted in SQL before it is
     * grouped, because a request filed at 07:00 Manila on the 1st is stored as
     * 23:00 UTC on the previous day (the previous year, in December), and a
     * UTC date bucket cannot be moved into the right month afterwards.
     */
    private function stackByMonth($rows): array
    {
        $months = [];
        $labels = [];

        foreach ($rows as $row) {
            $months[$row->month][$row->label] = ($months[$row->month][$row->label] ?? 0) + (int) $row->total;
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

        // Day pinned to 01: 'Y-m' alone would borrow today's day and roll Feb
        // over to March when the report is read on the 29th-31st.
        $monthLabels = array_map(
            fn ($key) => CarbonImmutable::createFromFormat('Y-m-d', $key.'-01', self::OFFICE_TIMEZONE)->format('M Y'),
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
                'tbl_service_request.status',
            ])
            ->get();

        $responseHours = [];
        // resolved_at is stamped on every terminal status, so this holds every closed request.
        $closedDays = [];
        // The median is time to a real resolution: a cancellation or a refusal is not one.
        $resolvedDays = [];

        foreach ($rows as $row) {
            $created = CarbonImmutable::parse($row->created_at, 'UTC');

            if ($row->first_responded_at !== null) {
                $responseHours[] = $created->diffInMinutes(CarbonImmutable::parse($row->first_responded_at, 'UTC')) / 60;
            }

            if ($row->resolved_at !== null) {
                $days = $created->diffInMinutes(CarbonImmutable::parse($row->resolved_at, 'UTC')) / 1440;
                $closedDays[] = $days;

                if ($row->status === 'Resolved') {
                    $resolvedDays[] = $days;
                }
            }
        }

        return [
            'firstResponse' => [
                'medianHours' => $this->median($responseHours),
                'n' => count($responseHours),
            ],
            'resolution' => [
                'medianDays' => $this->median($resolvedDays),
                'n' => count($resolvedDays),
            ],
            // Unchanged: every closed request, as before.
            'histogram' => $this->resolutionHistogram($closedDays),
            'coverage' => [
                'requests' => $rows->count(),
                'withFirstResponse' => count($responseHours),
                'withResolution' => count($closedDays),
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
        return self::openRequestAging($this->serviceId, $this->barangayId);
    }

    /**
     * Public and static because the dashboard shows the same buckets. The
     * dashboard's KPI strip can say "8 Pending" but not whether one of them
     * has been sitting there for six weeks, and staleness is a today problem
     * — so both surfaces read this, and neither can drift into its own
     * definition of what "open" or "7+ days" means.
     */
    public static function openRequestAging(?int $serviceId = null, ?int $barangayId = null): array
    {
        // Deliberately ignores any date window. A request filed last quarter
        // that is still open is exactly what this exists to surface, and
        // scoping it to a range would hide the oldest ones — the only ones
        // that matter here.
        $rows = DB::table('tbl_service_request')
            ->whereNotIn('tbl_service_request.status', ServiceRequest::TERMINAL_STATUSES)
            ->when($serviceId, fn ($q) => $q->where('tbl_service_request.service_id', $serviceId))
            // The request's own barangay, as filed.
            ->when($barangayId, fn ($q) => $q->where('tbl_service_request.barangay_id', $barangayId))
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
     * Section 6 — equipment utilization, including items never borrowed.
     *
     * Built from the full catalogue outward, not from the borrowing table
     * inward: a query that starts at tbl_equipment_borrowing and groups by
     * equipment_id can only ever list items that were borrowed at least
     * once, which is the one thing this section exists to correct. Dead
     * stock is half the purchasing decision and a chart of only borrowed
     * items hides it entirely.
     *
     * No barangay/service filter — an item's utilization is a fact about
     * the item, not about who borrowed it or what service they filed for,
     * and filtering the catalogue by either would make a zero-borrow row
     * ambiguous between "never borrowed" and "not borrowed by this
     * barangay". Only the date window narrows the borrow counts.
     */
    private function equipmentUtilization(): array
    {
        $items = DB::table('tbl_equipments')
            ->orderBy('item_name')
            ->select('equipment_id', 'item_name')
            ->get();

        $borrowed = DB::table('tbl_equipment_borrowing')
            ->where('created_at', '>=', $this->from)
            ->where('created_at', '<', $this->to)
            ->whereNotNull('equipment_id')
            ->groupBy('equipment_id')
            ->selectRaw('equipment_id, COUNT(*) as times, COALESCE(SUM(quantity), 0) as qty')
            ->get()
            ->keyBy('equipment_id');

        $rows = $items->map(function ($item) use ($borrowed) {
            $match = $borrowed->get($item->equipment_id);

            return [
                'label' => $item->item_name,
                'timesBorrowed' => $match ? (int) $match->times : 0,
                'quantityBorrowed' => $match ? (int) $match->qty : 0,
            ];
        })->sortByDesc('timesBorrowed')->values();

        return [
            'items' => $rows,
            'total' => (int) $rows->sum('timesBorrowed'),
            'zeroBorrowCount' => $rows->where('timesBorrowed', 0)->count(),
        ];
    }

    /**
     * Section 7 — loan turnaround and overdue.
     *
     * Three figures, each reading a different slice of the borrowing
     * lifecycle:
     *
     * - Median days out and the returned-late share are windowed by
     *   created_at, like every other section, and are asked only of
     *   borrowings that actually completed the leg they measure — a loan
     *   still out is neither on time nor late yet.
     * - Currently overdue DELIBERATELY ignores the date window, the same
     *   choice aging() makes for open requests: it is a present-moment
     *   backlog, and scoping it to a range would hide a loan that went out
     *   last quarter and never came back.
     *
     * Barangay filter applies (a loan is tied to the borrowing resident);
     * service filter does not (equipment borrowing has no service_id).
     */
    private function loanTurnaround(): array
    {
        $rows = $this->borrowingsScoped()
            ->select(['tbl_equipment_borrowing.due_date', 'tbl_equipment_borrowing.released_at', 'tbl_equipment_borrowing.returned_at'])
            ->get();

        $daysOut = [];
        $returnedCount = 0;
        $lateCount = 0;

        foreach ($rows as $row) {
            if ($row->released_at !== null && $row->returned_at !== null) {
                $daysOut[] = CarbonImmutable::parse($row->released_at, 'UTC')
                    ->diffInMinutes(CarbonImmutable::parse($row->returned_at, 'UTC')) / 1440;
            }

            if ($row->returned_at !== null) {
                $returnedCount++;

                if ($row->due_date !== null) {
                    $returnedDate = CarbonImmutable::parse($row->returned_at, 'UTC')->timezone(self::OFFICE_TIMEZONE)->toDateString();

                    if ($returnedDate > $row->due_date) {
                        $lateCount++;
                    }
                }
            }
        }

        return [
            'daysOut' => [
                'medianDays' => $this->median($daysOut),
                'n' => count($daysOut),
            ],
            'returnedLate' => [
                'count' => $lateCount,
                'of' => $returnedCount,
                'percent' => $returnedCount > 0 ? (int) round(($lateCount / $returnedCount) * 100) : null,
            ],
            'currentlyOverdue' => $this->currentlyOverdueLoans(),
        ];
    }

    /**
     * Base query for the loan section: the window, plus the barangay filter
     * joined through the borrowing resident. No service filter — equipment
     * borrowing carries no service_id.
     */
    private function borrowingsScoped()
    {
        return DB::table('tbl_equipment_borrowing')
            ->where('tbl_equipment_borrowing.created_at', '>=', $this->from)
            ->where('tbl_equipment_borrowing.created_at', '<', $this->to)
            ->when($this->barangayId, fn ($q) => $q
                ->join('tbl_residents', 'tbl_equipment_borrowing.resident_id', '=', 'tbl_residents.resident_id')
                ->where('tbl_residents.barangay_id', $this->barangayId));
    }

    /**
     * Released but not yet returned, past its due date, as of right now.
     * Ignores the date window on purpose — see loanTurnaround() above.
     */
    private function currentlyOverdueLoans(): int
    {
        return DB::table('tbl_equipment_borrowing')
            ->where('tbl_equipment_borrowing.status', 'Released')
            ->whereNotNull('tbl_equipment_borrowing.due_date')
            ->where('tbl_equipment_borrowing.due_date', '<', CarbonImmutable::now(self::OFFICE_TIMEZONE)->toDateString())
            ->when($this->barangayId, fn ($q) => $q
                ->join('tbl_residents', 'tbl_equipment_borrowing.resident_id', '=', 'tbl_residents.resident_id')
                ->where('tbl_residents.barangay_id', $this->barangayId))
            ->count();
    }

    /**
     * Section 8 — most used vehicles: trips per vehicle, ranked, from the
     * conduction (ambulance dispatch) trip log.
     *
     * `range` follows the page's date filter. Today / This week / This month
     * are the older fixed windows, all computed in one pass. Manila calendar boundaries: 'today' is
     * midnight-to-now, 'week' is Monday-to-now, 'month' is the 1st-to-now,
     * matching this class's other calendar-boxed windows (resolveRange())
     * rather than a rolling N-day lookback.
     *
     * Built from the full vehicle catalogue outward, the same shape as
     * equipmentUtilization() — a vehicle with no trips in the period still
     * shows at zero rather than dropping off the chart.
     *
     * No barangay or service filter: tbl_conduction_requests carries no
     * resident_id at all (filed by MDRRMO staff, not a resident — see the
     * table's own migration comment) and its parent service_request_id is
     * always the one ambulance-dispatch service, so a service filter would
     * either show everything or nothing.
     */
    private function mostUsedVehicles(): array
    {
        $now = CarbonImmutable::now(self::OFFICE_TIMEZONE);

        $boundaries = [
            'today' => $now->startOfDay(),
            'week' => $now->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $now->startOfMonth(),
        ];

        $vehicles = DB::table('tbl_vehicles')
            ->orderBy('unit_identifier')
            ->select('vehicle_id', 'unit_identifier', 'type')
            ->get();

        // 'range' follows the page's date filter; the panel reads only this.
        // today/week/month stay for the existing consumers of the payload.
        $windows = [
            'today' => [$boundaries['today']->utc(), null],
            'week' => [$boundaries['week']->utc(), null],
            'month' => [$boundaries['month']->utc(), null],
            'range' => [$this->from, $this->to],
        ];

        $result = [];

        foreach ($windows as $key => [$since, $until]) {
            $tripsByVehicle = DB::table('tbl_conduction_requests')
                ->where('created_at', '>=', $since)
                ->when($until, fn ($q) => $q->where('created_at', '<', $until))
                ->whereNotNull('vehicle_id')
                ->groupBy('vehicle_id')
                ->selectRaw('vehicle_id, COUNT(*) as total')
                ->pluck('total', 'vehicle_id');

            $result[$key] = $vehicles
                ->map(fn ($v) => [
                    'label' => $v->unit_identifier,
                    'type' => $v->type,
                    'trips' => (int) ($tripsByVehicle[$v->vehicle_id] ?? 0),
                ])
                ->sortByDesc('trips')
                ->values();
        }

        return $result;
    }

    /**
     * Section 9 — barangay: residents vs requests.
     *
     * Built from the full barangay roster outward, the same shape as
     * equipmentUtilization(): a query that starts at the requests and groups
     * by barangay can only ever list a barangay that has at least one, which
     * is exactly what hides "accounts but no requests" and "neither" — the
     * two facts this section exists to surface.
     *
     * Request counts reuse BarangayRequestCounts, the same helper the
     * dashboard and totals() use, so this cannot disagree with the rest of
     * the page about how many requests a barangay has. That helper counts
     * service requests and equipment loans together and does not take a
     * service filter, so neither does this section; resident counts are not
     * date-windowed at all — an account does not expire, and windowing it
     * would make a barangay's own resident count depend on which quarter is
     * selected.
     *
     * No barangay filter either: filtering the one section whose whole
     * purpose is the cross-barangay comparison down to a single barangay
     * would defeat it.
     */
    private function barangayResidentsVsRequests(): array
    {
        $counts = BarangayRequestCounts::forWindow($this->from, $this->to);
        $placedByName = collect($counts['barangays'])->keyBy('name');

        $residentCounts = DB::table('tbl_residents')
            ->where('account_type', 'head_of_family')
            ->whereNotNull('barangay_id')
            ->groupBy('barangay_id')
            ->selectRaw('barangay_id, COUNT(*) as total')
            ->pluck('total', 'barangay_id');

        $barangays = DB::table('tbl_barangay')
            ->orderBy('barangay_name')
            ->select('barangay_id', 'barangay_name')
            ->get()
            ->map(fn ($b) => [
                'name' => $b->barangay_name,
                'residents' => (int) ($residentCounts[$b->barangay_id] ?? 0),
                'requests' => (int) ($placedByName[$b->barangay_name]['requests'] ?? 0),
            ]);

        return [
            'barangays' => $barangays,
            'walkIn' => $counts['walkIn'],
            'totalResidents' => (int) $residentCounts->sum(),
            'totalRequests' => $counts['total'],
        ];
    }

    /**
     * Section 10 — app adoption: walk-in vs app-filed share by month.
     *
     * Reuses scoped() and stackByMonth(), the same base query and rollup
     * volumeByMonth() and outcomeByMonth() use, just labelled by origin
     * instead of service or status. That means it respects the barangay
     * filter exactly as scoped() already documents it should — a walk-in
     * carries no barangay, so filtering by one legitimately zeroes the
     * walk-in series rather than hiding the section.
     */
    private function appAdoptionByMonth(): array
    {
        $month = self::manilaMonth('tbl_service_request.created_at');
        $origin = "CASE WHEN tbl_service_request.resident_id IS NULL THEN 'Walk-in' ELSE 'App' END";

        $rows = $this->scoped()
            ->groupByRaw("{$month}, {$origin}")
            ->selectRaw("{$month} as month, {$origin} as label, COUNT(*) as total")
            ->get();

        return $this->stackByMonth($rows);
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
