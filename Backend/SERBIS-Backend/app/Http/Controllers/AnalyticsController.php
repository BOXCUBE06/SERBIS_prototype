<?php

namespace App\Http\Controllers;

use App\Models\ConductionRequest;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\Responder;
use App\Models\ServiceRequest;
use App\Models\SystemLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AmbulanceAvailability;
use App\Support\AdminSections;
use App\Support\AnalyticsCache;
use App\Support\AnalyticsReport;
use App\Support\BarangayRequestCounts;
use App\Support\PhoneNumber;
use App\Support\ReminderFollowUp;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * GET /admin/analytics — the retrospective page, as opposed to index()
     * below, which is the operational dashboard.
     *
     * Unlike the dashboard this one reads the request, so its cache key has to
     * discriminate on the filters. The key is built through AnalyticsCache so
     * it carries the current version and a write to any counted model strands
     * it; the database cache store has no tags and no pattern delete, so a
     * version counter is the only way to reach a keyspace this shape.
     *
     * Key growth is bounded: three of the four presets ignore from/to
     * entirely, custom ranges are clamped to whole days, and every entry
     * expires on the same five-minute TTL regardless.
     */
    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preset' => ['nullable', 'string', 'in:'.implode(',', AnalyticsReport::PRESETS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'barangay_id' => ['nullable', 'integer', 'exists:tbl_barangay,barangay_id'],
            'service_id' => ['nullable', 'integer', 'exists:tbl_services,service_id'],
        ]);

        [$from, $to, $preset] = AnalyticsReport::resolveRange(
            $validated['preset'] ?? null,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        $barangayId = isset($validated['barangay_id']) ? (int) $validated['barangay_id'] : null;
        $serviceId = isset($validated['service_id']) ? (int) $validated['service_id'] : null;

        $key = AnalyticsCache::key(sprintf(
            'report:%s:%s:%s:%s:%s',
            $preset,
            $from->toDateString(),
            $to->toDateString(),
            $barangayId ?? 'all',
            $serviceId ?? 'all',
        ));

        return response()->json(Cache::remember($key, AnalyticsCache::TTL_SECONDS, function () use ($from, $to, $preset, $barangayId, $serviceId) {
            $report = new AnalyticsReport($from, $to, $preset, $barangayId, $serviceId);

            // Same json round-trip as index(): config/cache.php sets
            // serializable_classes to false, so any Collection reaching the
            // cache comes back as __PHP_Incomplete_Class on a hit.
            return json_decode(json_encode($report->build()), true);
        }));
    }

    /**
     * What each headline card counts, by the section that owns the page it links
     * to. Carried on the card in the cached payload and removed before the
     * response leaves, so an admin sees the cards for the sections they hold.
     */
    private const KPI_SECTIONS = [
        'Total Residents' => AdminSections::RESIDENTS,
        'Pending Service Requests' => AdminSections::REQUESTS,
        'Pending Borrow Requests' => AdminSections::BORROWINGS,
        'Available Vehicles' => AdminSections::VEHICLES,
        'Pending Ambulance Requests' => AdminSections::AMBULANCE,
        'Equipment Overdue' => AdminSections::BORROWINGS,
    ];

    public function index(Request $request, AmbulanceAvailability $availability): JsonResponse
    {
        // Cached for 5 minutes (perf audit finding #2 — this endpoint ran
        // ~25 queries per admin dashboard load). One entry for everyone: the
        // payload is built without reading $request. What an admin may see of
        // it is cut down after the cache read (limitToSections), never by
        // keying the cache per admin, which would bring the ~25 queries back
        // once per account.
        //
        // The TTL is now a backstop rather than the only invalidation:
        // InvalidatesAnalyticsCache forgets this key on every write to a model
        // these numbers count, so a status change reaches the panel on the next
        // load instead of up to five minutes later.
        $payload = Cache::remember(AnalyticsCache::DASHBOARD_KEY, AnalyticsCache::TTL_SECONDS, function () {
            // 1. Calculate KPI Stats
            // Heads of the family only: a barangay or organization account is an
            // institution, not a household, and would inflate the headline count.
            $totalResidents = Resident::where('account_type', Resident::TYPE_HEAD_OF_FAMILY)->count();
            $pendingService = ServiceRequest::where('status', 'Pending')->count();
            $pendingBorrow = EquipmentBorrowing::where('status', 'Pending')->count();
            $availableVehicles = Vehicle::where('status', 'Available')->count();

            // tbl_conduction_requests has no status column — the form is filed by
            // MDRRMO staff and its only lifecycle is the trip log, so "pending"
            // here means the trip was never dispatched. Known limitation, recorded
            // in docs/dashboard-kpis.md: nothing ever closes a request that was
            // filed and then handled off-system, so those keep counting.
            $pendingAmbulance = ConductionRequest::whereNull('departed_office_at')->count();

            // Released but never returned, past its due date, as of right now.
            // Same definition AnalyticsReport::currentlyOverdueLoans() uses on
            // the Analytics page — surfaced here too since an overdue item is
            // a today problem, not something to notice only when someone
            // happens to open Analytics.
            $overdueBorrowings = EquipmentBorrowing::where('status', 'Released')
                ->whereNotNull('due_date')
                ->where('due_date', '<', Carbon::now('Asia/Manila')->toDateString())
                ->count();

            $kpiStats = [
                [
                    'title' => 'Total Residents',
                    'value' => number_format($totalResidents),
                    'icon' => 'mdi-account-group',
                    'color' => 'blue',
                    'subtitle' => 'Registered users in system',
                ],
                [
                    'title' => 'Pending Service Requests',
                    'value' => number_format($pendingService),
                    'icon' => 'mdi-clipboard-text-clock',
                    'color' => 'orange',
                    'subtitle' => 'Awaiting admin response',
                    'route' => ['path' => '/manage-requests', 'query' => ['status' => 'Pending']],
                ],
                [
                    'title' => 'Pending Borrow Requests',
                    'value' => number_format($pendingBorrow),
                    'icon' => 'mdi-hand-extended',
                    'color' => 'orange',
                    'subtitle' => 'Equipment requests',
                    'route' => ['path' => '/borrowings', 'query' => ['status' => 'Pending']],
                ],
                [
                    'title' => 'Available Vehicles',
                    'value' => number_format($availableVehicles),
                    'icon' => 'mdi-ambulance',
                    'color' => 'green',
                    'subtitle' => 'Units ready for dispatch',
                    'route' => ['path' => '/vehicles'],
                ],
            ];

            // Fifth card only when there is something to act on. A standing zero is
            // not information, and the panel lays the strip out from the number of
            // cards it receives, so four fill the row on their own.
            if ($pendingAmbulance > 0) {
                $kpiStats[] = [
                    'title' => 'Pending Ambulance Requests',
                    'value' => number_format($pendingAmbulance),
                    'icon' => 'mdi-clock-alert-outline',
                    'color' => 'error',
                    'subtitle' => 'Filed, not yet dispatched',
                    'route' => ['path' => '/conduction-requests'],
                ];
            }

            // Same rule as the ambulance card above: only shown when there is
            // something to chase, since a standing zero is not information.
            if ($overdueBorrowings > 0) {
                $kpiStats[] = [
                    'title' => 'Equipment Overdue',
                    'value' => number_format($overdueBorrowings),
                    'icon' => 'mdi-alert-circle-outline',
                    'color' => 'error',
                    'subtitle' => 'Past due date, not yet returned',
                    'route' => ['path' => '/borrowings'],
                ];
            }

            // 2. Fetch Recent Service Requests
            $serviceRequests = ServiceRequest::with(['resident.barangay', 'service'])
                ->latest()
                ->take(5)
                ->get()
                ->map(function ($req) {
                    return [
                        'resident' => $req->resident ? $req->resident->first_name.' '.$req->resident->last_name : 'Unknown',
                        // Fetch the barangay name through the nested relationship
                        'barangay' => ($req->resident && $req->resident->barangay) ? $req->resident->barangay->barangay_name : 'Unknown Barangay',
                        // Null only for an "Others" request now that service_id is
                        // nullable (MDRRMO feedback, 2026-09-17) — 'Other', not
                        // 'Unknown', since this is an intentional resident choice.
                        'type' => $req->service ? $req->service->service_name : 'Other',
                        'date' => $req->created_at->format('M j, Y h:i A'),
                        'status' => $req->status,
                        // Which board the request belongs to, so the list can be
                        // cut down per admin after the cache read. Removed
                        // before the response leaves (limitToSections).
                        'section' => AdminSections::forServiceCode($req->service?->code),
                    ];
                });

            // 3. Fetch Recent Borrow Requests
            $borrowRequests = EquipmentBorrowing::with(['resident.barangay', 'equipment'])
                ->latest()
                ->take(5)
                ->get()
                ->map(function ($req) {
                    return [
                        'borrower' => $req->resident ? $req->resident->first_name.' '.$req->resident->last_name : 'Unknown',
                        // Fetch the barangay name through the nested relationship
                        'barangay' => ($req->resident && $req->resident->barangay) ? $req->resident->barangay->barangay_name : 'Unknown Barangay',
                        'equipment' => $req->equipment ? $req->equipment->item_name : 'Unknown Item',
                        'date' => $req->created_at->format('M j, Y h:i A'),
                        'status' => $req->status,
                    ];
                });

            // 4. Fetch Recent System Logs
            $systemLogs = SystemLog::with('admin')
                ->latest()
                ->take(5)
                ->get()
                ->map(function ($log) {
                    return [
                        'time' => $log->created_at->format('h:i A'),
                        'user' => $log->admin ? $log->admin->first_name : 'System', // Adjust based on your User model columns
                        'module' => class_basename($log->auditable_type), // Converts "App\Models\ServiceRequest" to "ServiceRequest"
                        'action' => $log->action_type,
                    ];
                });

            // Push-only notices that reached no device (ReminderFollowUp). With no
            // SMS behind them, this list is how staff learn whom to ring. Three
            // days, so a Friday miss is still here on Monday.
            $followUps = SystemLog::with('resident:resident_id,first_name,last_name,phone_number')
                ->where('action_type', ReminderFollowUp::ACTION)
                ->where('created_at', '>=', now()->subDays(3))
                ->latest()
                ->take(10)
                ->get()
                ->map(fn ($log) => [
                    'name' => $log->resident ? trim($log->resident->first_name.' '.$log->resident->last_name) : 'Unknown resident',
                    'phone' => $log->resident ? PhoneNumber::display((string) $log->resident->phone_number) : '',
                    'what' => ReminderFollowUp::LABELS[$log->new_values['kind'] ?? ''] ?? 'Reminder',
                    'time' => $log->created_at->format('M j, h:i A'),
                ]);

            // Sections 5-7 aggregate in the database and return counts, not rows.
            // Every construct below is standard SQL that MySQL and Postgres both
            // accept: JOIN, COUNT(*), GROUP BY on real columns, and
            // CAST(x AS DATE). Date *formatting* stays in PHP on purpose —
            // DATE_FORMAT() is MySQL-only (Postgres spells it to_char), and the
            // filled series is at most 30 rows, so there is nothing to win by
            // pushing it down.

            // The map, the barangay ranking and the category breakdown each got
            // their own Today/Week/Month/All-time filter (previously one shared
            // toggle drove the hero card, the trend chart and nothing else — these
            // three were silently always all-time). Four periods, computed once
            // here so the panel has every filter position in the one response
            // instead of a request per toggle click.
            $periods = [
                'today' => Carbon::today(),
                'week' => Carbon::today()->subDays(6),
                'month' => Carbon::today()->subDays(29),
                'all' => null,
            ];

            // 5. Heatmap (Choropleth): request counts per barangay.
            //
            // BarangayRequestCounts LEFT JOINs and reports the unplaced rows
            // separately. The join here used to be inner, which dropped every
            // walk-in request — resident_id is null on a request filed at the
            // counter, so 20 of 50 rows locally never reached the map and the
            // card's totals were 40% short with nothing saying so.
            //
            // A walk-in still cannot be drawn: no barangay is recorded for it
            // anywhere, and inventing one would be worse than omitting it. It
            // is surfaced as its own count beside the ranking instead, which
            // is what makes the section reconcile.
            $mapDataByPeriod = [];
            $walkInByPeriod = [];
            $totalsByPeriod = [];

            foreach ($periods as $periodKey => $since) {
                $counts = BarangayRequestCounts::forWindow($since);

                $mapDataByPeriod[$periodKey] = $counts['barangays'];
                $walkInByPeriod[$periodKey] = $counts['walkIn'];
                $totalsByPeriod[$periodKey] = $counts['total'];
            }

            // 6. Pie Chart Data (Services vs Items), same per-period treatment.
            $pieServicesSince = fn (?Carbon $since) => ServiceRequest::query()
                ->join('tbl_services', 'tbl_service_request.service_id', '=', 'tbl_services.service_id')
                ->when($since, fn ($q) => $q->where('tbl_service_request.created_at', '>=', $since))
                ->groupBy('tbl_services.service_name')
                ->orderByDesc('total')
                ->selectRaw('tbl_services.service_name as label, COUNT(*) as total')
                ->pluck('total', 'label');

            $pieItemsSince = fn (?Carbon $since) => EquipmentBorrowing::query()
                ->join('tbl_equipments', 'tbl_equipment_borrowing.equipment_id', '=', 'tbl_equipments.equipment_id')
                ->when($since, fn ($q) => $q->where('tbl_equipment_borrowing.created_at', '>=', $since))
                ->groupBy('tbl_equipments.item_name')
                ->orderByDesc('total')
                ->selectRaw('tbl_equipments.item_name as label, COUNT(*) as total')
                ->pluck('total', 'label');

            $pieByPeriod = [];
            foreach ($periods as $periodKey => $since) {
                $pieServices = $pieServicesSince($since);
                $pieItems = $pieItemsSince($since);
                $pieByPeriod[$periodKey] = [
                    'services' => ['labels' => $pieServices->keys(), 'data' => $pieServices->values()],
                    'items' => ['labels' => $pieItems->keys(), 'data' => $pieItems->values()],
                ];
            }

            // 7. Bar Chart Data (Weekly vs Monthly).
            // The week is a subset of the month, so each table is read once over the
            // 30-day window and the 7-day series is derived from the same counts.
            $last30Days = Carbon::today()->subDays(29);

            $countByDay = fn (string $table) => DB::table($table)
                ->where('created_at', '>=', $last30Days)
                ->groupByRaw('CAST(created_at AS DATE)')
                ->selectRaw('CAST(created_at AS DATE) as day, COUNT(*) as total')
                ->pluck('total', 'day');

            $dailyTotals = [];

            foreach ([$countByDay('tbl_service_request'), $countByDay('tbl_equipment_borrowing')] as $counts) {
                foreach ($counts as $day => $total) {
                    $key = Carbon::parse($day)->format('Y-m-d');
                    $dailyTotals[$key] = ($dailyTotals[$key] ?? 0) + (int) $total;
                }
            }

            // Fill in empty days with 0 for the Bar Chart
            $fillDates = function (int $days) use ($dailyTotals) {
                $result = [];
                for ($i = $days; $i >= 0; $i--) {
                    $date = Carbon::today()->subDays($i);
                    $result[$date->format('M d')] = $dailyTotals[$date->format('Y-m-d')] ?? 0;
                }

                return $result;
            };

            $weekSeries = $fillDates(6);
            $monthSeries = $fillDates(29);

            // json round-trip, not a plain return: serviceRequests, borrowRequests,
            // systemLogs, mapDataByPeriod and charts.pieByPeriod all hold
            // Illuminate\Support\Collection instances (from ->map()/->keys()/
            // ->values()), and config/cache.php sets serializable_classes to
            // false — FileStore::get() then calls unserialize() with
            // allowed_classes => false, which refuses to restore any object on a
            // cache hit and hands back a broken __PHP_Incomplete_Class instead.
            // json_encode already knows how to flatten a Collection (it
            // implements JsonSerializable); decoding that back with true turns
            // the whole structure into plain arrays, so nothing but scalars and
            // arrays ever reaches the cache.
            return json_decode(json_encode([
                'kpiStats' => $kpiStats,
                'serviceRequests' => $serviceRequests,
                'borrowRequests' => $borrowRequests,
                'systemLogs' => $systemLogs,
                'followUps' => $followUps,
                'mapDataByPeriod' => $mapDataByPeriod,
                // Requests that carry no barangay at all, per period, and the
                // reconciled section total. The panel prints both beside the
                // ranking so the numbers on screen add up to the real count.
                'walkInByPeriod' => $walkInByPeriod,
                'totalsByPeriod' => $totalsByPeriod,
                // Same buckets as the analytics page, from the same method:
                // the KPI strip can say "8 Pending" but not whether one of
                // them is six weeks old, and that is a today problem.
                'aging' => AnalyticsReport::openRequestAging(),
                'charts' => [
                    'pieByPeriod' => $pieByPeriod,
                    'bar' => [
                        'week' => ['labels' => array_keys($weekSeries), 'data' => array_values($weekSeries)],
                        'month' => ['labels' => array_keys($monthSeries), 'data' => array_values($monthSeries)],
                    ],
                ],
            ]), true);
        });

        $payload = $this->limitToSections($payload, $request->user());

        // Outside the cache: neither model invalidates it, and both answers are
        // "right now". Counts and ids only, so no section gate.
        $payload['responders'] = [
            'available' => Responder::where('status', 'available')->count(),
            'total' => Responder::count(),
        ];
        $payload['bookingConflicts'] = $availability->conflictingRequestIds(now(), now()->addDay());

        return response()->json($payload);
    }

    /**
     * Cuts the shared dashboard payload down to the sections this admin holds.
     *
     * Applied after the cache read, so the cached entry stays whole and the same
     * for everyone. Two kinds of thing go: the cards and lists that link to a
     * page the admin cannot open, and every list that names a person (recent
     * requests and borrowings, the activity feed, the follow-up calls). The
     * counts and charts are aggregates with no one named in them, and stay for
     * whoever holds the Dashboard.
     *
     * Fails closed: a card whose title is not in KPI_SECTIONS, or a request row
     * with no marker, is dropped rather than shown.
     */
    private function limitToSections(array $payload, mixed $admin): array
    {
        $can = fn (string $section) => $admin instanceof User && $admin->canAccess($section);

        $payload['kpiStats'] = collect($payload['kpiStats'] ?? [])
            ->filter(fn (array $card) => $can(self::KPI_SECTIONS[$card['title'] ?? ''] ?? ''))
            ->values()
            ->all();

        $payload['serviceRequests'] = collect($payload['serviceRequests'] ?? [])
            ->filter(fn (array $row) => $can($row['section'] ?? ''))
            ->map(fn (array $row) => Arr::except($row, 'section'))
            ->values()
            ->all();

        $payload['borrowRequests'] = $can(AdminSections::BORROWINGS) ? ($payload['borrowRequests'] ?? []) : [];

        $payload['systemLogs'] = $can(AdminSections::LOGS) ? ($payload['systemLogs'] ?? []) : [];

        // Equipment due-back and available-again notices, and the ambulance
        // booking reminders, are the two things staff ring residents about.
        $payload['followUps'] = ($can(AdminSections::BORROWINGS) || $can(AdminSections::AMBULANCE))
            ? ($payload['followUps'] ?? [])
            : [];

        return $payload;
    }
}
