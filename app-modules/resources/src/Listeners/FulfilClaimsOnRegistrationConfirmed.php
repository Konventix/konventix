<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners;

use Konventix\Platform\Modules\Concerns\ShortCircuitsInactiveModule;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Resources\Actions\CreateSeatClaims;
use Konventix\Resources\Actions\FulfilResourceClaims;

/**
 * Materializes a confirmed registration's grants (seating design §7.2):
 * `resource_claim` grants become first-fit bookings, `seating` grants
 * become unplaced seat claims. Admission grants are Admission's business.
 *
 * Gated on the payload's brand rather than the ambient one: a paid type
 * confirms from Commerce's webhook, which runs without a brand in context.
 * A brand without Resources still registers people correctly — their claims
 * simply stay unfulfilled, and their seat claims are never created.
 */
final class FulfilClaimsOnRegistrationConfirmed
{
    use ShortCircuitsInactiveModule;

    public function __construct(
        private readonly FulfilResourceClaims $fulfil,
        private readonly CreateSeatClaims $createSeatClaims,
    ) {}

    protected function moduleName(): string
    {
        return 'resources';
    }

    public function handle(RegistrationConfirmed $event): void
    {
        if (! $this->moduleActiveForBrand($event->brandId)) {
            return;
        }

        $this->fulfil->handle(
            registrationId: $event->registrationId,
            eventId: $event->eventId,
            brandId: $event->brandId,
            grants: $event->grants,
        );

        $this->createSeatClaims->handle(
            registrationId: $event->registrationId,
            eventId: $event->eventId,
            brandId: $event->brandId,
            registrantUserId: $event->registrantUserId,
            grants: $event->grants,
        );
    }
}
