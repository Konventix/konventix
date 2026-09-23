<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers\Admin;

use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Admission\Enums\Permission;
use Konventix\Admission\Http\Controllers\Admin\Concerns\ScopesToOperator;
use Konventix\Admission\Queries\TicketDetailQuery;

/**
 * Operator console: one ticket (attendee-list design §4.4, D-ATT-05, #329)
 * — its reference, state, validity, holder and buyer, type, door checks,
 * full check-in history, the registration as Admission knows it, the badge
 * link and the same check-in / check-out actions as the list. The
 * capability first (403), then the operator (404).
 */
final class TicketDetailController
{
    use ScopesToOperator;

    public function show(string $admission, TicketDetailQuery $query): Response
    {
        Gate::authorize(Permission::CheckInAttendees->value);

        $model = $this->findAdmission($admission);

        return Inertia::render('admin/tickets/Show', [
            'ticket' => $query->handle($model, now(), Gate::allows(Permission::PrintBadge->value)),
        ]);
    }
}
