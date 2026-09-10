<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners;

use Konventix\Platform\Modules\Concerns\ShortCircuitsInactiveModule;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Resources\Actions\FulfilResourceClaims;

/**
 * Materializes `resource_claim` grants into bookings when a registration
 * confirms.
 *
 * Until this existed, `resource_claim` grants were silently dropped —
 * Admission's issuance skips any grant whose kind is not `'admission'`, and
 * nothing else listened. That was intended (the axis existed before the
 * module did), but it means this listener is the first thing that gives those
 * grants any effect at all.
 *
 * Resources is Pennant-gated, so this short-circuits when the module is off
 * for the event's brand — a brand without Resources still registers people
 * correctly, their claims just stay unfulfilled.
 */
final class FulfilClaimsOnRegistrationConfirmed
{
    use ShortCircuitsInactiveModule;

    public function __construct(
        private readonly FulfilResourceClaims $action,
    ) {}

    protected function moduleName(): string
    {
        return 'resources';
    }

    public function handle(RegistrationConfirmed $event): void
    {
        // The payload's brand, not the ambient one: a paid registration is
        // confirmed from Stripe's webhook, which runs with no brand in
        // context, and `isActive()` would read that as "off".
        if (! $this->moduleActiveForBrand($event->brandId)) {
            return;
        }

        $this->action->handle(
            registrationId: $event->registrationId,
            eventId: $event->eventId,
            brandId: $event->brandId,
            grants: $event->grants,
        );
    }
}
