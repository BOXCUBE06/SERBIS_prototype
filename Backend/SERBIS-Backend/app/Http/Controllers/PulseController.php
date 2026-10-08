<?php

namespace App\Http\Controllers;

use App\Models\ConductionRequest;
use App\Models\EquipmentBorrowing;
use App\Models\Resident;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\AdminSections;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the admin panel polls to learn that a list changed, instead of
 * refetching the lists themselves.
 *
 * Each kind is `{count, latest}`: a new or deleted row moves the count, an
 * edit moves the newest `updated_at`. The panel compares both against the rows
 * it has on screen, so its own writes never read as someone else's. One
 * aggregate query per kind, no eager loads and no cache, so a tab can ask
 * every 30 seconds. Only the kinds the caller's sections cover are returned.
 */
class PulseController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $request->user();
        $can = fn (string $section) => $admin instanceof User && $admin->canAccess($section);
        $isAmbulance = fn ($service) => $service->where('code', AdminSections::AMBULANCE_SERVICE_CODE);

        $pulse = [];

        if ($can(AdminSections::REQUESTS)) {
            $pulse['requests'] = $this->stamp(
                ServiceRequest::query()->where(fn ($rows) => $rows->whereNull('service_id')->orWhereDoesntHave('service', $isAmbulance)),
                "status = 'Pending'",
            );
        }

        if ($can(AdminSections::AMBULANCE)) {
            // A booking is waiting on staff until a unit is assigned to it
            // (approve() sets vehicle_id; the status stays Booked).
            $pulse['ambulance'] = $this->stamp(
                ServiceRequest::query()->whereHas('service', $isAmbulance),
                "status = 'Pending' OR (status = 'Booked' AND vehicle_id IS NULL)",
            );
            $pulse['trips'] = $this->stamp(ConductionRequest::query());
        }

        if ($can(AdminSections::BORROWINGS)) {
            $pulse['borrowings'] = $this->stamp(EquipmentBorrowing::query(), "status = 'Pending'");
        }

        if ($can(AdminSections::RESIDENTS)) {
            // 'Inactive' is the stored value for a self-registered account
            // waiting for staff to activate it; the panel labels it Pending.
            $pending = Resident::query()
                ->where('status', 'Inactive')
                ->selectRaw('COUNT(*) as total, SUM(account_type = ?) as organizations', [Resident::TYPE_ORGANIZATION])
                ->first();

            $pulse['residents'] = $this->stamp(Resident::query()) + [
                'pending' => (int) $pending->total,
                'pending_organizations' => (int) $pending->organizations,
            ];
        }

        return response()->json($pulse);
    }

    /**
     * `$waiting`, when given, is the SQL condition for a row waiting on a staff
     * decision; its count (`waiting`) is the sidebar badge. Same query.
     */
    private function stamp(Builder $query, ?string $waiting = null): array
    {
        $select = 'COUNT(*) as total, MAX(updated_at) as latest'
            .($waiting ? ", COALESCE(SUM({$waiting}), 0) as waiting" : '');
        $row = $query->toBase()->selectRaw($select)->first();

        // The same ISO form the list endpoints serialise updated_at in, so the
        // panel can compare this against the newest row it has on screen.
        return [
            'count' => (int) $row->total,
            'latest' => $row->latest ? CarbonImmutable::parse($row->latest)->toJSON() : null,
        ] + ($waiting ? ['waiting' => (int) $row->waiting] : []);
    }
}
