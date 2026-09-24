<?php

namespace App\Http\Controllers;

use App\Models\ConductionRequest;
use App\Models\EquipmentBorrowing;
use App\Models\ServiceRequest;
use App\Models\SystemLog;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Records that a list or record was printed or exported from the admin panel.
 *
 * The printing and file-writing happen in the browser, so the server only ever
 * hears about it here: one Activity Log row per print/export with who did it,
 * what kind of record, how many and in which format. It carries no record
 * data, only the count and (for small selections) the ids.
 *
 * Which section may call it is decided per record type by the route's
 * `section:` middleware, the same permission the list itself needs.
 */
class ExportLogController extends Controller
{
    /** Record type (the route's {type}) => the model whose rows the log points at. */
    public const MODELS = [
        'request' => ServiceRequest::class,
        'booking' => ServiceRequest::class,
        'trip' => ConductionRequest::class,
        'borrowing' => EquipmentBorrowing::class,
        'vehicle' => Vehicle::class,
    ];

    public function store(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:print,export',
            'format' => 'required|in:print,csv,xlsx',
            'scope' => 'required|in:single,selected,all',
            'count' => 'required|integer|min:1|max:100000',
            // Only sent for small selections; a whole-list export is just a count.
            'ids' => 'nullable|array|max:200',
            'ids.*' => 'integer|min:1',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);

        $ids = $validated['ids'] ?? [];

        SystemLog::create([
            'admin_id' => $request->user()->getKey(),
            'action_type' => $validated['action'] === 'print' ? 'printed' : 'exported',
            'auditable_type' => self::MODELS[$type],
            // NOT NULL column: 0 stands for "many records" on a bulk run.
            'auditable_id' => $ids[0] ?? 0,
            'new_values' => array_filter([
                'type' => $type,
                'format' => $validated['format'],
                'scope' => $validated['scope'],
                'count' => $validated['count'],
                'ids' => $ids ?: null,
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
            ], fn ($value) => $value !== null),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['logged' => true], 201);
    }
}
