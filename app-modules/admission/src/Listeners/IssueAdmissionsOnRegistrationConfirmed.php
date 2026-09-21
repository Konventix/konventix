<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Actions\IssueAdmissionsForRegistration;
use Konventix\Registration\Events\RegistrationConfirmed;

/**
 * The trigger: issue one admission per granted admission in the payload
 * (SPEC.md "Consumes"). Registered in
 * `Konventix\Admission\Providers\AdmissionServiceProvider::registerListeners()`.
 *
 * Admission is a Core module (never gated), so unlike a Pennant-gated
 * module's listener this one does not need
 * `ShortCircuitsInactiveModule` — but it must still be idempotent and
 * replayable, which `IssueAdmissionsForRegistration` guarantees.
 *
 * `Konventix\Registration\Events\RegistrationConfirmed` is the one foreign
 * class this module imports at all, and it is allowed precisely because
 * events are the public contract between modules (ADR-0004) — the general
 * project architecture test (`tests/Unit/ArchitectureTest.php`) exempts the
 * `Events\` sub-namespace for exactly this reason. Everything past this
 * line — `IssueAdmissionsForRegistration` and below — only ever sees the
 * scalar payload unpacked from it, never the event object itself. See
 * `tests/RegistrationCommerceImportBoundaryTest.php`, which greps this
 * module's `src/` for `use Konventix\Registration` / `use Konventix\Commerce`
 * imports and fails on anything outside `Registration\Events\`.
 */
final class IssueAdmissionsOnRegistrationConfirmed
{
    public function __construct(
        private readonly IssueAdmissionsForRegistration $action,
    ) {}

    public function handle(RegistrationConfirmed $event): void
    {
        $this->action->handle(
            registrationId: $event->registrationId,
            eventId: $event->eventId,
            brandId: $event->brandId,
            registrantUserId: $event->registrantUserId,
            grants: $event->grants,
            // ADR-0066 §6.4: beside the grants, never inside one. The
            // listener unpacks it like every other scalar in the payload and
            // the Action below never learns where it came from.
            entryConditions: $event->entryConditionKeys,
        );
    }
}
