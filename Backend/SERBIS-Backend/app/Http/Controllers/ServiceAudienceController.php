<?php

namespace App\Http\Controllers;

use App\Models\Resident;
use App\Models\Service;
use App\Models\ServiceAudience;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The admin side of the audience filter: which account types may request each
 * service. Admin-only (see routes/api.php); residents never call this, they
 * just get a shorter list from GET /services.
 */
class ServiceAudienceController extends Controller
{
    /**
     * Every service, plus the two that are not tbl_services rows, each with the
     * account types currently allowed to request it.
     */
    public function index()
    {
        $rows = Service::orderBy('service_id')->get(['service_id', 'service_name', 'code', 'is_active'])
            ->map(fn (Service $service) => [
                'code' => $service->code,
                'name' => $service->service_name,
                'is_service' => true,
                'is_active' => (bool) $service->is_active,
                'account_types' => ServiceAudience::typesFor($service->code),
            ]);

        $pseudo = collect(ServiceAudience::PSEUDO_SERVICES)->map(fn (string $name, string $code) => [
            'code' => $code,
            'name' => $name,
            'is_service' => false,
            'is_active' => true,
            'account_types' => ServiceAudience::typesFor($code),
        ])->values();

        return response()->json(['data' => $rows->concat($pseudo)->values()]);
    }

    /**
     * Replaces the allowed types for one service. At least one is required: an
     * empty set would be read as "open to everyone" (see the migration), the
     * opposite of a row of unticked boxes, and a service is switched off with
     * is_active instead.
     */
    public function update(Request $request, string $code)
    {
        $known = Service::where('code', $code)->exists() || isset(ServiceAudience::PSEUDO_SERVICES[$code]);

        if (! $known) {
            return response()->json(['message' => 'Service not found'], 404);
        }

        $validated = $request->validate([
            'account_types' => ['required', 'array', 'min:1'],
            'account_types.*' => ['required', 'distinct', Rule::in(Resident::ACCOUNT_TYPES)],
        ], [
            'account_types.min' => 'Choose at least one account type. To stop a service being requested, switch it off in Manage Services.',
        ]);

        DB::transaction(function () use ($code, $validated) {
            // Read the current set through typesFor()'s own query rather than
            // treating "no rows" as empty, so a service that was implicitly open
            // and is now narrowed gets the rows it keeps.
            $existing = ServiceAudience::where('service_code', $code)->get();

            foreach ($existing->whereNotIn('account_type', $validated['account_types']) as $row) {
                $row->delete();
            }

            foreach ($validated['account_types'] as $type) {
                if ($existing->where('account_type', $type)->isEmpty()) {
                    ServiceAudience::create(['service_code' => $code, 'account_type' => $type]);
                }
            }
        });

        return response()->json([
            'code' => $code,
            'account_types' => ServiceAudience::typesFor($code),
        ]);
    }
}
