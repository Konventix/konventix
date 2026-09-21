<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Actions\RevokeUnitsForRegistration;
use Konventix\Registration\Events\RegistrationQuantityReduced;

/**
 * The trigger for a partial cancellation (D-REG-15): the contractual act
 * bought fewer units, so that many access rights end. Registered in
 * `Konventix\Admission\Providers\AdmissionServiceProvider::registerListeners()`.
 *
 * `Konventix\Registration\Events\RegistrationQuantityReduced` is a third
 * sanctioned foreign import beside `RegistrationConfirmed` and
 * `RegistrationCancelled` — events are the public contract between modules
 * (ADR-0004) and all three live in Registration's `Events\` namespace, the
 * only one `tests/RegistrationCommerceImportBoundaryTest.php` permits.
 * Everything past this line only ever sees scalars.
 *
 * **This listener is synchronous on purpose, and that is the ordering
 * mechanism.** It runs during `event()`, inside
 * `ReduceRegistrationQuantity`'s own transaction. When fewer than
 * `removedUnits` admissions are free the Action below throws, the throw
 * propagates out of the dispatch and out of the transaction, and the
 * reduction row, the released quota units and the new quantity roll back
 * with it. Commerce's refund listener on the same event is queued with
 * `$afterCommit = true`, so its job is pushed only when that transaction
 * commits — which it now never does. Nothing has to know about anything
 * else for a refused revocation to stop the money.
 *
 * How many units to take is read off `removedUnits` rather than counted out
 * of `removedGrants`: an `admission`-kind grant may be worth more than one
 * admission per unit (a double ticket), and the event already multiplied
 * that in. Summing the admission grants' quantities is therefore the right
 * number and `removedUnits` is not — see `unitsToRevoke()`.
 */
final class RevokeUnitsOnRegistrationQuantityReduced
{
    private const string ADMISSION_GRANT_KIND = 'admission';

    public function __construct(
        private readonly RevokeUnitsForRegistration $action,
    ) {}

    public function handle(RegistrationQuantityReduced $event): void
    {
        $units = $this->unitsToRevoke($event);

        if ($units < 1) {
            // A type that grants no admissions at all — a pure seating or
            // resource package. Nothing here to give back; Resources and
            // Commerce still do their half.
            return;
        }

        $this->action->handle(
            registrationId: $event->registrationId,
            reductionId: $event->reductionId,
            units: $units,
            revokedByUserId: $event->reducedByUserId,
        );
    }

    /**
     * The admissions the removed units were worth — `removedGrants` is
     * already `grant quantity × removed units` (the same multiplication
     * `RegistrationConfirmed` carries), so this is a plain sum over the
     * `admission`-kind entries. `'admission'` is a literal because this
     * module never imports Registration's `GrantKind`: the string is the
     * contract.
     */
    private function unitsToRevoke(RegistrationQuantityReduced $event): int
    {
        $units = 0;

        foreach ($event->removedGrants as $grant) {
            if ($grant['kind'] === self::ADMISSION_GRANT_KIND) {
                $units += $grant['quantity'];
            }
        }

        return $units;
    }
}
