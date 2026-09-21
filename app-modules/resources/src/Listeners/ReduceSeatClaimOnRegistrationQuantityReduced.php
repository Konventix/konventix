<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners;

use Konventix\Registration\Events\RegistrationQuantityReduced;
use Konventix\Resources\Actions\ReduceSeatClaim;

/**
 * A confirmed registration bought fewer units, so its seat entitlement
 * shrinks by the places those units were worth (D-REG-15). Registered in
 * `ResourcesServiceProvider::registerListeners()` — discovery is off
 * (`.ai/rules/listeners.md`).
 *
 * **Ungated**, like `ReleaseBookingsOnRegistrationCancelled` beside it
 * (ADR-0060's explicit ADR-0003 exception): giving seats back is safe and
 * idempotent, and a brand that switched the module off must not keep places
 * parked for units nobody bought any more.
 *
 * The number of places comes from `removedGrants`' `seating` entries, which
 * already carry `grant quantity × removed units` — this module never learns
 * that a registration has a quantity, exactly as at confirmation.
 * `'seating'` stays a literal: the string is the contract, not
 * Registration's enum (ADR-0004).
 *
 * The Action is resolved inside `handle()`, never constructor-injected
 * alongside a scoped binding, and it reads `BrandContext` at call time
 * (`.ai/rules/listeners.md`).
 */
final class ReduceSeatClaimOnRegistrationQuantityReduced
{
    private const string SEATING_GRANT_KIND = 'seating';

    public function __construct(
        private readonly ReduceSeatClaim $action,
    ) {}

    public function handle(RegistrationQuantityReduced $event): void
    {
        $removedSeats = 0;

        foreach ($event->removedGrants as $grant) {
            if ($grant['kind'] === self::SEATING_GRANT_KIND) {
                $removedSeats += $grant['quantity'];
            }
        }

        if ($removedSeats < 1) {
            return;
        }

        $this->action->handle(
            registrationId: $event->registrationId,
            reductionId: $event->reductionId,
            removedSeats: $removedSeats,
        );
    }
}
