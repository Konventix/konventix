<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Konventix\Admission\Actions\CheckInFromConsole;
use Konventix\Admission\Data\ConsoleCheckInData;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Enums\Permission;
use Konventix\Admission\Exceptions\ConsoleCheckInRefusedException;
use Konventix\Admission\Http\Controllers\Admin\Concerns\ScopesToOperator;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Support\LatestAcceptedCheckIn;
use Konventix\Admission\Support\OwedDoorChecks;
use Konventix\Admission\Support\TicketReference;

/**
 * Operator console: check a ticket in or out by hand (attendee-list design
 * §4.3, §5, D-ATT-03, D-ATT-04, #329). Route middleware already requires the
 * admin host, auth, `platform.access-admin` and the
 * `admission-console-check-in` rate limit; here the capability comes first
 * (403), then the ticket and its operator (404), then the answers, then
 * `CheckInFromConsole`.
 *
 * It only translates. The check-in is decided by the core, never by the
 * dialog: a client that ignores a disabled button gets the same answer. A
 * refusal before any write (`ConsoleCheckInRefusedException`) and a
 * door-equivalent rejection (a rejected `CheckIn`) both come back as one
 * sentence under `check_in`, which the dialog shows — never a 500.
 *
 * The event is the ticket's own, from the admission the route names, never
 * from input (carried ruling 1).
 */
final class ConsoleCheckInController
{
    use ScopesToOperator;

    public function checkIn(Request $request, string $admission, CheckInFromConsole $checkIn): RedirectResponse
    {
        Gate::authorize(Permission::CheckInAttendees->value);

        $model = $this->findAdmission($admission);

        $data = ConsoleCheckInData::validateAndCreate($request->all());
        $data->ensureAnswersOwed(array_column(
            OwedDoorChecks::for($model, LatestAcceptedCheckIn::dayFor($model->brand_id, $model->event_id, now())),
            'key',
        ));

        $this->record($request, $checkIn, $model, 'in', $data->conditions);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket :reference is checked in.', ['reference' => TicketReference::of($model->id)])]);

        return back();
    }

    public function checkOut(Request $request, string $admission, CheckInFromConsole $checkIn): RedirectResponse
    {
        Gate::authorize(Permission::CheckInAttendees->value);

        $model = $this->findAdmission($admission);

        $this->record($request, $checkIn, $model, 'out', []);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket :reference is checked out.', ['reference' => TicketReference::of($model->id)])]);

        return back();
    }

    /**
     * @param  list<array{key: string, outcome: string}>  $conditions
     *
     * @throws ValidationException
     */
    private function record(Request $request, CheckInFromConsole $checkIn, Admission $admission, string $direction, array $conditions): void
    {
        try {
            $row = $checkIn->handle(
                admission: $admission,
                eventId: $admission->event_id,
                actorUserId: (string) ($request->user() ?? abort(401))->getAuthIdentifier(),
                direction: $direction,
                conditionsChecked: $conditions,
            );
        } catch (ConsoleCheckInRefusedException $refusal) {
            throw ValidationException::withMessages(['check_in' => $refusal->getMessage()]);
        }

        if ($row->result === CheckInResult::Rejected) {
            throw ValidationException::withMessages([
                'check_in' => CheckInRejectionReason::tryFrom((string) $row->reason_code)?->consoleSentence($direction)
                    ?? __('The check-in was refused.'),
            ]);
        }
    }
}
