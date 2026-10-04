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
use App\Support\PhoneNumber;
use App\Support\ReminderFollowUp;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * GET /admin/analytics/barangays — one row per barangay for the map's hover
     * card: how many households are registered there and how many requests are
     * waiting on staff right now.
     *
     * Built from the barangay roster outward, so a barangay with no residents
     * and no requests is still a row, at zero. Residents are heads of the
     * family, as everywhere else; pending is service requests in status
     * Pending, by the filing account's barangay. A walk-in has no barangay and
     * is not in any row. Live rather than cached: three grouped counts over a
     * 64-row roster.
     */
    public function barangays(): JsonResponse
    {
        $residents = DB::table('tbl_residents')
            ->where('account_type', Resident::TYPE_HEAD_OF_FAMILY)
            ->groupBy('barangay_id')
            ->selectRaw('barangay_id, COUNT(*) as total')
            ->pluck('total', 'barangay_id');

        // By the barangay each request was filed under (walk-ins have none).
        $pending = DB::table('tbl_service_request')
            ->whereNotNull('barangay_id')
            ->where('status', 'Pending')
            ->groupBy('barangay_id')
            ->selectRaw('barangay_id, COUNT(*) as total')
            ->pluck('total', 'barangay_id');

        $rows = DB::table('tbl_barangay')
            ->orderBy('barangay_name')
            ->get(['barangay_id', 'barangay_name', 'psgc_code'])
            ->map(fn ($b) => [
                'psgc_code' => $b->psgc_code,
                'name' => $b->barangay_name,
                'residents_count' => (int) ($residents[$b->barangay_id] ?? 0),
                'pending_requests_count' => (int) ($pending[$b->barangay_id] ?? 0),
            ]);

        return response()->json(['data' => $rows]);
    }

    /** A booking starting this soon already holds its unit; later ones only show in the Today rail. */
    private const UNIT_HOLD_HOURS = 2;

    /**
     * GET /admin/dashboard — what the dashboard panel reads: the activity feed,
     * the follow-up calls, the "Most requested" ranking per period, and the
     * live responder / unit / booking-conflict figures. The headline cards and
     * the open-request queues are built in the panel from the lists it already
     * fetches, so none of that is computed here.
     */
    public function index(Request $request, AmbulanceAvailability $availability): JsonResponse
    {
        // Cached for AnalyticsCache::TTL_SECONDS (5 minutes; perf audit finding
        // #2 — this endpoint once ran ~25 queries per admin dashboard load).
        // One entry for everyone: the payload is built without reading
        // $request. What an admin may see of it is cut down after the cache
        // read (limitToSections), never by keying the cache per admin, which
        // would bring the queries back once per account.
        //
        // The TTL is now a backstop rather than the only invalidation:
        // InvalidatesAnalyticsCache forgets this key on every write to a model
        // these numbers count, so a status change reaches the panel on the next
        // load instead of up to five minutes later.
        $payload = Cache::remember(AnalyticsCache::DASHBOARD_KEY, AnalyticsCache::TTL_SECONDS, function () {
            // 1. Recent System Logs
            $systemLogs = SystemLog::with('admin')
                ->latest()
                ->take(5)
                ->get()
                ->map(function ($log) {
                    return [
                        'time' => $log->created_at->format('h:i A'),
                        'user' => $log->admin ? $log->admin->displayName() : 'System', // Adjust based on your User model columns
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

            // 2. "Most requested" per period, all four computed once so the
            // panel's filter switches without a request. Day boundaries are
            // Manila midnights taken to UTC: created_at is stored UTC, and a
            // UTC "today" would start at 08:00 office time. Week and month are
            // rolling 7 and 30 days including today.
            $today = CarbonImmutable::now('Asia/Manila')->startOfDay();
            $periods = [
                'today' => $today->utc(),
                'week' => $today->subDays(6)->utc(),
                'month' => $today->subDays(29)->utc(),
                'all' => null,
            ];

            // Left joins: a request with no service ("Others") or a loan of an
            // item outside the catalogue has no name row and would vanish from
            // an inner join, leaving the ranking short of the real count.
            $pieServicesSince = fn (?CarbonInterface $since) => ServiceRequest::query()
                ->leftJoin('tbl_services', 'tbl_service_request.service_id', '=', 'tbl_services.service_id')
                ->when($since, fn ($q) => $q->where('tbl_service_request.created_at', '>=', $since))
                ->groupByRaw("COALESCE(tbl_services.service_name, 'Others')")
                ->orderByDesc('total')
                ->selectRaw("COALESCE(tbl_services.service_name, 'Others') as label, COUNT(*) as total")
                ->pluck('total', 'label');

            $pieItemsSince = fn (?CarbonInterface $since) => EquipmentBorrowing::query()
                ->leftJoin('tbl_equipments', 'tbl_equipment_borrowing.equipment_id', '=', 'tbl_equipments.equipment_id')
                ->when($since, fn ($q) => $q->where('tbl_equipment_borrowing.created_at', '>=', $since))
                ->groupByRaw("COALESCE(tbl_equipments.item_name, 'Others')")
                ->orderByDesc('total')
                ->selectRaw("COALESCE(tbl_equipments.item_name, 'Others') as label, COUNT(*) as total")
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

            // json round-trip, not a plain return: systemLogs, followUps and
            // charts.pieByPeriod hold Illuminate\Support\Collection instances
            // (from ->map()/->keys()/->values()), and config/cache.php sets
            // serializable_classes to false — FileStore::get() then calls
            // unserialize() with allowed_classes => false, which refuses to
            // restore any object on a cache hit and hands back a broken
            // __PHP_Incomplete_Class instead. json_encode already knows how to
            // flatten a Collection (it implements JsonSerializable); decoding
            // that back with true turns the whole structure into plain arrays,
            // so nothing but scalars and arrays ever reaches the cache.
            return json_decode(json_encode([
                'systemLogs' => $systemLogs,
                'followUps' => $followUps,
                'charts' => ['pieByPeriod' => $pieByPeriod],
            ]), true);
        });

        $payload = $this->limitToSections($payload, $request->user());

        // Outside the cache: these answers are "right now" and the cache is not
        // invalidated by the clock. Counts and ids only, so no section gate.
        $payload['responders'] = [
            'available' => Responder::where('status', 'available')->count(),
            'total' => Responder::count(),
        ];
        $payload['bookingConflicts'] = $availability->conflictingRequestIds(now(), now()->addDay());
        $payload['units'] = ['free' => $this->unitsFreeNow(), 'total' => Vehicle::count()];

        return response()->json($payload);
    }

    /**
     * Units that could leave now: not in Maintenance, not out on a trip
     * (departed, not yet returned), and not due on a booking within the hold.
     */
    private function unitsFreeNow(): int
    {
        $onTrip = ConductionRequest::query()
            ->select('vehicle_id')
            ->whereNotNull('vehicle_id') // NOT IN against a NULL matches nothing
            ->whereNotNull('departed_office_at')
            ->whereNull('returned_office_at');

        return Vehicle::query()
            ->where('status', '!=', 'Maintenance')
            ->whereNotIn('vehicle_id', $onTrip)
            ->whereDoesntHave('serviceRequests', fn ($request) => $request
                ->whereNotIn('status', ServiceRequest::TERMINAL_STATUSES)
                ->whereHas('ambulanceBooking', fn ($booking) => $booking
                    ->where('scheduled_at', '>=', now())
                    ->where('scheduled_at', '<', now()->addHours(self::UNIT_HOLD_HOURS))))
            ->count();
    }

    /**
     * Cuts the shared dashboard payload down to the sections this admin holds.
     *
     * Applied after the cache read, so the cached entry stays whole and the same
     * for everyone. The activity feed and the follow-up calls name people, so
     * they go to admins who hold the section they come from. The ranking is an
     * aggregate with no one named in it, and stays for whoever holds the
     * Dashboard.
     */
    private function limitToSections(array $payload, mixed $admin): array
    {
        $can = fn (string $section) => $admin instanceof User && $admin->canAccess($section);

        $payload['systemLogs'] = $can(AdminSections::LOGS) ? ($payload['systemLogs'] ?? []) : [];

        // Equipment due-back and available-again notices, and the ambulance
        // booking reminders, are the two things staff ring residents about.
        $payload['followUps'] = ($can(AdminSections::BORROWINGS) || $can(AdminSections::AMBULANCE))
            ? ($payload['followUps'] ?? [])
            : [];

        return $payload;
    }
}
