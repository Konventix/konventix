<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Actions\RevokeAdmissionsForRegistration;
use Konventix\Registration\Events\RegistrationCancelled;

/**
 * The trigger: the contractual act ended, so the access rights it produced
 * end with it (ADR-0057 point 6, SPEC.md "Consumes" — `RegistrationCancelled`).
 * Registered in
 * `Konventix\Admission\Providers\AdmissionServiceProvider::registerListeners()`.
 *
 * This is the *only* path by which a cancellation reaches Admission, and it
 * is deliberately the only one: `PaymentReversed` has no listener in this
 * module. A reversal revives Commerce's receivable and leaves Registration
 * confirmed until the repayment deadline (ADR-0057 points 4 and 5), and the
 * door has no business knowing any of that — ADR-0005. By the time a
 * reversal reaches here it has become an actual cancellation.
 *
 * `Konventix\Registration\Events\RegistrationCancelled` is a second
 * sanctioned foreign import beside `RegistrationConfirmed`, allowed because
 * events are the public contract between modules (ADR-0004) and both live in
 * Registration's `Events\` namespace — see
 * `tests/RegistrationCommerceImportBoundaryTest.php`, which greps this
 * module's `src/` and fails on anything outside it. Everything past this
 * line only ever sees the scalar payload.
 *
 * `cancelledByUserId` is null where nobody acted — ADR-0057 point 4's
 * unpaid-reversal sweep — and passing it straight through is why
 * `AdmissionRevoked::$revokedByUserId` is nullable too.
 *
 * Admission is a Core module (never gated), so unlike a Pennant-gated
 * module's listener this one does not need `ShortCircuitsInactiveModule` —
 * but it must still be idempotent and replayable, which
 * `RevokeAdmissionsForRegistration` guarantees.
 */
final class RevokeAdmissionsOnRegistrationCancelled
{
    public function __construct(
        private readonly RevokeAdmissionsForRegistration $action,
    ) {}

    public function handle(RegistrationCancelled $event): void
    {
        $this->action->handle(
            registrationId: $event->registrationId,
            revokedByUserId: $event->cancelledByUserId,
        );
    }
}
