<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Actions\IssueAdmissionsForRegistration;
use Konventix\Registration\Events\RegistrationAmended;

/**
 * The delta counterpart to `IssueAdmissionsOnRegistrationConfirmed`
 * (ADR-0036): grants were added to an already-confirmed registration, so
 * issue exactly those and nothing else. Registered in
 * `Konventix\Admission\Providers\AdmissionServiceProvider::registerListeners()`.
 *
 * The payload's `grants` are only the *added* grants, and `amendmentId`
 * scopes the idempotency check — so this can safely fire against a
 * registration that already has admissions from its original confirmation,
 * and a replay of the same amendment still issues nothing new.
 *
 * Like its sibling, it imports exactly one foreign class, from the
 * `Registration\Events\` sub-namespace that is the sanctioned public
 * contract (ADR-0004); `tests/RegistrationCommerceImportBoundaryTest.php`
 * enforces that boundary. Everything past this line sees scalars only.
 */
final class IssueAdmissionsOnRegistrationAmended
{
    public function __construct(
        private readonly IssueAdmissionsForRegistration $action,
    ) {}

    public function handle(RegistrationAmended $event): void
    {
        $this->action->handle(
            registrationId: $event->registrationId,
            eventId: $event->eventId,
            brandId: $event->brandId,
            grants: $event->grants,
            amendmentId: $event->amendmentId,
        );
    }
}
