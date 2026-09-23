<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Admission\Data\AttendeeRowData;
use Konventix\Admission\Enums\Permission;
use Konventix\Admission\Http\Controllers\Admin\Concerns\ScopesToOperator;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Queries\AttendeeListQuery;
use Konventix\Admission\Support\LatestAcceptedCheckIn;
use Konventix\Admission\Support\OwedDoorChecks;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;

/**
 * Operator console: the attendee list of one event (attendee-list design
 * §4.1, §4.2, §5, #329). Route middleware already requires the admin host,
 * auth and `platform.access-admin`; here the capability comes first (403
 * without it), then the operator scope (404 for another operator's event, or
 * an event this module has no summary of).
 *
 * The door checks each row owes are read for the whole page in one query
 * (`OwedDoorChecks::forMany()`), on the event day that covers now — the day a
 * check-in from the dialog would land on.
 */
final class AttendeeListController
{
    use HasDataTableQuery;
    use ScopesToOperator;

    public function index(Request $request, string $event, AttendeeListQuery $query): Response
    {
        Gate::authorize(Permission::CheckInAttendees->value);

        $summary = $this->findEventSummary($event);

        $perPage = $this->resolvePerPage($request, default: 25);
        $sort = $this->resolveSort($request, AttendeeListQuery::SORTS, AttendeeListQuery::SORT_ACTIVITY);

        // Newest activity first unless the reader chose a direction (§4.1).
        if (! $request->filled('sort')) {
            $sort['direction'] = 'desc';
        }

        $filters = $this->resolveFilters($request, AttendeeListQuery::FILTERS);
        $search = $request->filled('search') ? $request->string('search')->toString() : null;
        $now = now();

        $attendees = $query->handle($summary, $search, $filters, $sort, $perPage, $now);

        $owed = OwedDoorChecks::forMany(
            $attendees->getCollection()->filter(
                fn (Admission $admission): bool => AttendeeRowData::admits($admission->state, $this->lastDirection($admission)),
            )->values(),
            LatestAcceptedCheckIn::dayFor($summary->brand_id, $summary->event_id, $now),
        );

        return Inertia::render('admin/attendees/Index', [
            'event' => ['id' => $summary->event_id, 'name' => $summary->name ?? $summary->event_id],
            'attendees' => $attendees->through(
                fn (Admission $admission): AttendeeRowData => AttendeeRowData::ofListRow($admission, $owed[$admission->id] ?? []),
            ),
            'filters' => $this->currentFilters($request, $sort, $perPage, $filters),
            'canPrintBadge' => Gate::allows(Permission::PrintBadge->value),
        ]);
    }

    private function lastDirection(Admission $admission): ?string
    {
        $direction = $admission->getAttribute('last_direction');

        return is_string($direction) ? $direction : null;
    }
}
