<?php

namespace App\Http\Controllers;

use App\Models\SystemLog;
use App\Traits\PaginatesLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemLogController extends Controller
{
    use PaginatesLists;

    public function index(Request $request): JsonResponse
    {
        $query = SystemLog::with(['admin' => function ($query) {
            $query->select('admin_id', 'first_name', 'last_name');
        }])
            ->orderBy('created_at', 'desc')
        // Tiebreaker, and it is load-bearing now that this pages. Several log rows
        // routinely share a second, and MySQL does not promise a stable order
        // among ties — without a unique second key a row can be handed out on both
        // page 1 and page 2, or on neither, and the reader has no way to notice.
            ->orderBy('log_id', 'desc');

        $this->applySearch($query, (string) $request->query('search', ''));

        // Paginated because this table only ever grows: every create, update,
        // delete and login across the panel appends a row, and nothing prunes it.
        // See App\Traits\PaginatesLists for why the other list endpoints did not
        // get the same treatment.
        $logs = $query->paginate($this->resolvePerPage($request));

        $mapped = collect($logs->items())->map(function ($log) {
            return [
                'created_at' => $log->created_at,
                'user' => $log->admin ? [
                    'name' => $log->admin->first_name.' '.$log->admin->last_name,
                ] : ['name' => 'System'],
                'module' => class_basename($log->auditable_type), // e.g. "Resident" instead of "App\Models\Resident"
                'action' => $log->action_type,
                'description' => $this->buildDescription($log),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $mapped,
            'meta' => $this->paginationMeta($logs),
        ]);
    }

    /**
     * Server-side search, added with pagination rather than after it: the Logs
     * page has always had a search box, and it was Vuetify's client-side filter
     * over the whole table. Paginating without moving the search across would
     * leave a box that quietly only searches the current page — a row on page 3
     * becomes unfindable and the screen gives no sign of it.
     *
     * `module` and `description` are built in PHP, not stored, so they cannot be
     * matched directly. They are derived from `auditable_type`, `auditable_id`
     * and `action_type`, which are matched here instead — typing "Resident"
     * still finds the rows whose description reads "Created new Resident".
     */
    private function applySearch($query, string $search): void
    {
        $search = trim($search);

        if ($search === '') {
            return;
        }

        // Escape the LIKE wildcards, or a search for "100%" matches everything.
        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function ($q) use ($term) {
            $q->where('action_type', 'like', $term)
                ->orWhere('auditable_type', 'like', $term)
                ->orWhere('auditable_id', 'like', $term)
                ->orWhereHas('admin', function ($admin) use ($term) {
                    // Matched on the concatenation as well as the parts, so a
                    // search for "Juan Dela Cruz" finds the row that a search for
                    // "Juan" and a search for "Dela Cruz" both already found.
                    $admin->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$term]);
                });
        });
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
            $fields = implode(', ', $changed);

            return "Updated {$module} (ID: {$log->auditable_id}) — fields: {$fields}";
        }

        if ($action === 'deleted') {
            return "Deleted {$module} (ID: {$log->auditable_id})";
        }

        return ucfirst($action)." {$module} (ID: {$log->auditable_id})";
    }
}
