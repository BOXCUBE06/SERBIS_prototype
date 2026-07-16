<?php

namespace App\Http\Controllers;

use App\Models\Resident;
use App\Models\ServiceRequest;
use App\Models\EquipmentBorrowing;
use App\Models\Equipment;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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

         // 5. Calculate Heatmap (Choropleth) Data
        $serviceReqs = ServiceRequest::with('resident.barangay')->get();
        $borrowReqs = EquipmentBorrowing::with('resident.barangay')->get();
        
        // Merge both request types
        $allRequests = $serviceReqs->concat($borrowReqs);

        $mapData = $allRequests->groupBy(function ($req) {
            return ($req->resident && $req->resident->barangay) ? $req->resident->barangay->barangay_name : 'Unknown Barangay';
        })->map(function ($group, $barangayName) {
            return [
                'name' => $barangayName,
                'requests' => $group->count()
            ];
        })->reject(function ($item) {
            return $item['name'] === 'Unknown Barangay'; // Hide unknowns from the map
        })->values();

        // 6. Calculate Pie Chart Data (Services vs Items)
        $pieServices = ServiceRequest::with('service')->get()
            ->groupBy(function ($req) {
                return $req->service ? $req->service->service_name : 'Unknown';
            })->map->count();

        $pieItems = EquipmentBorrowing::with('equipment')->get()
            ->groupBy(function ($req) {
                return $req->equipment ? $req->equipment->item_name : 'Unknown';
            })->map->count();

        // 7. Calculate Bar Chart Data (Weekly vs Monthly)
        $last7Days = \Carbon\Carbon::today()->subDays(6);
        $last30Days = \Carbon\Carbon::today()->subDays(29);

        // Function to group by date
        $groupByDate = function ($collection, $startDate) {
            return $collection->where('created_at', '>=', $startDate)
                ->groupBy(function ($item) {
                    return $item->created_at->format('M d');
                })->map->count();
        };

        $weekRequests = $groupByDate($allRequests, $last7Days);
        $monthRequests = $groupByDate($allRequests, $last30Days);

        // Fill in empty days with 0 for the Bar Chart
        $fillDates = function ($counts, $days) {
            $result = [];
            for ($i = $days; $i >= 0; $i--) {
                $date = \Carbon\Carbon::today()->subDays($i)->format('M d');
                $result[$date] = $counts->get($date, 0);
            }
            return $result;
        };

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
                    'week' => ['labels' => array_keys($fillDates($weekRequests, 6)), 'data' => array_values($fillDates($weekRequests, 6))],
                    'month' => ['labels' => array_keys($fillDates($monthRequests, 29)), 'data' => array_values($fillDates($monthRequests, 29))],
                ]
            ]
        ]);
       
    }
}