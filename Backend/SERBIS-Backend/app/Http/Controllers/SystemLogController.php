<?php

namespace App\Http\Controllers;

use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;

class SystemLogController extends Controller
{
    public function index(): JsonResponse
{
    $logs = SystemLog::with(['admin' => function($query) {
        $query->select('admin_id', 'first_name', 'last_name'); 
    }])
    ->orderBy('created_at', 'desc')
    ->get();

    $mapped = $logs->map(function ($log) {
        return [
            'created_at'  => $log->created_at,
            'user'        => $log->admin ? [
                'name' => $log->admin->first_name . ' ' . $log->admin->last_name
            ] : ['name' => 'System'],
            'module'      => class_basename($log->auditable_type), // e.g. "Resident" instead of "App\Models\Resident"
            'action'      => $log->action_type,
            'description' => $this->buildDescription($log),
        ];
    });

    return response()->json([
        'success' => true,
        'data'    => $mapped
    ]);
}

private function buildDescription(SystemLog $log): string
{
    $module = class_basename($log->auditable_type);
    $action = strtolower($log->action_type);

    if ($action === 'created') {
        return "Created new {$module} (ID: {$log->auditable_id})";
    }

    if ($action === 'updated' && $log->old_values && $log->new_values) {
        $changed = array_keys(array_diff_assoc($log->new_values, $log->old_values));
        $fields  = implode(', ', $changed);
        return "Updated {$module} (ID: {$log->auditable_id}) — fields: {$fields}";
    }

    if ($action === 'deleted') {
        return "Deleted {$module} (ID: {$log->auditable_id})";
    }

    return ucfirst($action) . " {$module} (ID: {$log->auditable_id})";
}
}