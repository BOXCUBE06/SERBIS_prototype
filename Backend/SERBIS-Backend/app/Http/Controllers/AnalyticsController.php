<?php

namespace App\Http\Controllers;

use App\Models\Resident;
use App\Models\ServiceRequest;
use App\Models\EquipmentBorrowing;
use App\Models\Equipment;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
   public function index(Request $request): JsonResponse
    {
        // 1. Calculate KPI Stats
        $totalResidents = Resident::count();
        $pendingService = ServiceRequest::where('status', 'Pending')->count();
        $pendingBorrow = EquipmentBorrowing::where('status', 'Pending')->count();
        $availableEquipment = Equipment::sum('available_quantity'); // Based on tbl_equipments column

        $kpiStats = [
            [
                'title' => 'Total Residents',
                'value' => number_format($totalResidents),
                'icon' => 'mdi-account-group',
                'color' => 'blue',
                'subtitle' => 'Registered users in system'
            ],
            [
                'title' => 'Pending Service',
                'value' => number_format($pendingService),
                'icon' => 'mdi-clipboard-text-clock',
                'color' => 'orange',
                'subtitle' => 'Awaiting admin response'
            ],
            [
                'title' => 'Pending Borrow',
                'value' => number_format($pendingBorrow),
                'icon' => 'mdi-hand-extended',
                'color' => 'orange',
                'subtitle' => 'Equipment requests'
            ],
            [
                'title' => 'Available Equipment',
                'value' => number_format($availableEquipment),
                'icon' => 'mdi-toolbox',
                'color' => 'green',
                'subtitle' => 'Items ready for dispatch'
            ],
        ];

        // 2. Fetch Recent Service Requests
        $serviceRequests = ServiceRequest::with(['resident.barangay', 'service'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($req) {
                return [
                    'resident' => $req->resident ? $req->resident->first_name . ' ' . $req->resident->last_name : 'Unknown',
                    // Fetch the barangay name through the nested relationship
                    'barangay' => ($req->resident && $req->resident->barangay) ? $req->resident->barangay->barangay_name : 'Unknown Barangay',
                    'type' => $req->service ? $req->service->service_name : 'Unknown Service', 
                    'date' => $req->created_at->format('M j, Y h:i A'),
                    'status' => $req->status,
                ];
            });

        // 3. Fetch Recent Borrow Requests
        $borrowRequests = EquipmentBorrowing::with(['resident.barangay', 'equipment'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($req) {
                return [
                    'borrower' => $req->resident ? $req->resident->first_name . ' ' . $req->resident->last_name : 'Unknown',
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

        // Sections 5-7 aggregate in the database and return counts, not rows.
        // Every construct below is standard SQL that MySQL and Postgres both
        // accept: JOIN, COUNT(*), GROUP BY on real columns, and
        // CAST(x AS DATE). Date *formatting* stays in PHP on purpose —
        // DATE_FORMAT() is MySQL-only (Postgres spells it to_char), and the
        // filled series is at most 30 rows, so there is nothing to win by
        // pushing it down.

        // 5. Heatmap (Choropleth): request counts per barangay.
        // The join is inner, which drops rows with no resident or no barangay —
        // matching the old behaviour, which bucketed them as 'Unknown Barangay'
        // and then rejected them.
        $countByBarangay = fn (string $table, string $model) => $model::query()
            ->join('tbl_residents', "{$table}.resident_id", '=', 'tbl_residents.resident_id')
            ->join('tbl_barangay', 'tbl_residents.barangay_id', '=', 'tbl_barangay.barangay_id')
            ->groupBy('tbl_barangay.barangay_name')
            ->selectRaw('tbl_barangay.barangay_name as name, COUNT(*) as total')
            ->pluck('total', 'name');

        $serviceByBarangay = $countByBarangay('tbl_service_request', ServiceRequest::class);
        $borrowByBarangay = $countByBarangay('tbl_equipment_borrowing', EquipmentBorrowing::class);

        $mapData = $serviceByBarangay->keys()
            ->merge($borrowByBarangay->keys())
            ->unique()
            ->map(fn ($name) => [
                'name' => $name,
                'requests' => (int) $serviceByBarangay->get($name, 0) + (int) $borrowByBarangay->get($name, 0),
            ])
            ->sortByDesc('requests')
            ->values();

        // 6. Pie Chart Data (Services vs Items)
        $pieServices = ServiceRequest::query()
            ->join('tbl_services', 'tbl_service_request.service_id', '=', 'tbl_services.service_id')
            ->groupBy('tbl_services.service_name')
            ->orderByDesc('total')
            ->selectRaw('tbl_services.service_name as label, COUNT(*) as total')
            ->pluck('total', 'label');

        $pieItems = EquipmentBorrowing::query()
            ->join('tbl_equipments', 'tbl_equipment_borrowing.equipment_id', '=', 'tbl_equipments.equipment_id')
            ->groupBy('tbl_equipments.item_name')
            ->orderByDesc('total')
            ->selectRaw('tbl_equipments.item_name as label, COUNT(*) as total')
            ->pluck('total', 'label');

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

        return response()->json([
            'kpiStats' => $kpiStats,
            'serviceRequests' => $serviceRequests,
            'borrowRequests' => $borrowRequests,
            'systemLogs' => $systemLogs,
            'mapData' => $mapData,
            'charts' => [
                'pie' => [
                    'services' => ['labels' => $pieServices->keys(), 'data' => $pieServices->values()],
                    'items' => ['labels' => $pieItems->keys(), 'data' => $pieItems->values()],
                ],
                'bar' => [
                    'week' => ['labels' => array_keys($weekSeries), 'data' => array_values($weekSeries)],
                    'month' => ['labels' => array_keys($monthSeries), 'data' => array_values($monthSeries)],
                ]
            ]
        ]);
    }
}
