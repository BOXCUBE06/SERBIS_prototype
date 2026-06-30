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
        $serviceRequests = ServiceRequest::with(['resident', 'service'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($req) {
                return [
                    'resident' => $req->resident ? $req->resident->first_name . ' ' . $req->resident->last_name : 'Unknown',
                    'type' => $req->service ? $req->service->service_name : 'Unknown Service', // Adjust 'service_name' if your tbl_services uses a different column
                    'date' => $req->created_at->format('F d, Y'),
                    'status' => $req->status,
                ];
            });

        // 3. Fetch Recent Borrow Requests
        $borrowRequests = EquipmentBorrowing::with(['resident', 'equipment'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($req) {
                return [
                    'borrower' => $req->resident ? $req->resident->first_name . ' ' . $req->resident->last_name : 'Unknown',
                    'equipment' => $req->equipment ? $req->equipment->item_name : 'Unknown Item',
                    'date' => $req->created_at->format('F d, Y'),
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

        // Return the exact JSON structure expected by the Vue component
        return response()->json([
            'kpiStats' => $kpiStats,
            'serviceRequests' => $serviceRequests,
            'borrowRequests' => $borrowRequests,
            'systemLogs' => $systemLogs,
        ]);
    }
}