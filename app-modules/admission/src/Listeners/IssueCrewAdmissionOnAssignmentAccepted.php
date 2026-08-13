<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Actions\IssueCrewAdmission;
use Konventix\Staffing\Events\AssignmentAccepted;

/**
 * D-STF-01's contract, from Admission's side: "an accepted assignment
 * auto-creates the crew Admission via event contract (Staffing emits,
 * Admission listens)".
 *
 * `Konventix\Staffing\Events\AssignmentAccepted` is a second sanctioned
 * cross-module import, alongside Registration's — the `Events\` sub-namespace
 * is the one exemption (ADR-0004). Everything past this line sees scalars.
 *
 * Note this widens the boundary test's allowance: it previously asserted this
 * module imports nothing outside `Registration\Events\`, which was true only
 * because Staffing did not exist yet.
 */
final class IssueCrewAdmissionOnAssignmentAccepted
{
    public function __construct(
        private readonly IssueCrewAdmission $action,
    ) {}

    public function handle(AssignmentAccepted $event): void
    {
        $this->action->handle(
            assignmentId: $event->assignmentId,
            eventId: $event->eventId,
            brandId: $event->brandId,
            userId: $event->userId,
            role: $event->role,
        );
    }
}
