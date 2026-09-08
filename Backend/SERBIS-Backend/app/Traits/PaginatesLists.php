<?php

namespace App\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Page-size resolution and the meta block for paginated list endpoints
 * (backend audit #10).
 *
 * Only the two log endpoints use this today. That is deliberate and the
 * reasoning belongs here rather than in a commit message nobody re-reads:
 *
 * `paginate()` changes a response from a bare array to an envelope, which
 * breaks every consumer of that route at once. The admin panel is not the only
 * consumer — the Flutter app reads `/services`, `/service-requests`,
 * `/info-materials`, `/advisories` and `/barangays` as lists.
 *
 * How it breaks there is worth stating exactly, because the obvious guess is
 * wrong (checked against the client on 2026-08-21). ApiService::_decode wraps a
 * bare array as `{'data': [...]}` and listFrom() then reads `data['data']` — and
 * that is the same key `paginate()` puts its rows under. So paginating one of
 * these does NOT empty the screen. It silently truncates it to the first 25
 * rows, with no envelope the client knows how to page through and nothing on
 * screen saying anything is missing. A resident would simply stop seeing their
 * older requests. That is harder to notice than a blank screen, not easier.
 *
 * `/logs/system` and `/logs/sms` have exactly one consumer each (LogsView) and
 * are the two tables that grow without bound, which is what audit #10 is
 * actually about.
 *
 * The remaining list endpoints were left alone on purpose. `/residents`,
 * `/admin/service-requests` and `/borrowings` all have views that filter,
 * tab and count over the *whole* array client-side; paginating the server
 * without moving that logic across gives a search box that cannot find a row
 * on another page — worse than no pagination, because it looks like it works.
 * `/vehicles`, `/equipments`, `/admins` and `/admin/info-materials` are
 * bounded sets (a fleet, an inventory, the office staff), so the payload was
 * never the problem there.
 *
 * All fourteen GET index routes were walked on 2026-08-21 to settle how much of
 * audit #10 was left: two paginate (both log endpoints, through this trait) and
 * twelve do not, each for one of the three reasons above. Nothing is
 * outstanding that can be fixed by adding `paginate()` alone — the remaining
 * work is moving client-side filtering to the server, which is a feature, not
 * an audit item.
 *
 * When one of those is picked up, reuse this trait so every paginated
 * endpoint answers with the same meta keys.
 */
trait PaginatesLists
{
    /** Page size when the caller does not ask for one. */
    private const DEFAULT_PER_PAGE = 25;

    /**
     * Ceiling on `?per_page`. Without it a caller can ask for the entire
     * table in one page and undo the reason the endpoint paginates at all.
     */
    private const MAX_PER_PAGE = 100;

    protected function resolvePerPage(Request $request): int
    {
        // `integer()` yields 0 for a missing or non-numeric value, so garbage
        // (`?per_page=all`) falls back to the default instead of erroring —
        // this is a display knob, not something worth a 422 over.
        $requested = $request->integer('per_page', self::DEFAULT_PER_PAGE);

        if ($requested < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }

    /**
     * The subset of the paginator worth sending. Laravel's own payload
     * includes fully-qualified `*_page_url` links built from the request host,
     * which are wrong the moment the API sits behind a proxy and are unused by
     * a client that already knows its own base URL.
     */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            // Null on an empty page rather than 0, so "showing 0 to 0" is not
            // rendered as a range that sounds like it contains something.
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
