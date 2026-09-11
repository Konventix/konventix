<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners;

use Konventix\Platform\Modules\Concerns\ShortCircuitsInactiveModule;
use Konventix\Registration\Events\RegistrationAmended;
use Konventix\Resources\Actions\CreateSeatClaims;
use Konventix\Resources\Actions\FulfilResourceClaims;

/**
 * The delta counterpart to `FulfilClaimsOnRegistrationConfirmed`
 * (ADR-0036, seating design §7.2): the payload carries only the added
 * grants, and `amendmentId` keys both the bookings and the second seat
 * claim, so a replay of the same amendment adds nothing.
 */
final class FulfilClaimsOnRegistrationAmended
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

    public function handle(RegistrationAmended $event): void
    {
        if (! $this->moduleActiveForBrand($event->brandId)) {
            return;
        }

        $this->fulfil->handle(
            registrationId: $event->registrationId,
            eventId: $event->eventId,
            brandId: $event->brandId,
            grants: $event->grants,
            amendmentId: $event->amendmentId,
        );

        $this->createSeatClaims->handle(
            registrationId: $event->registrationId,
            eventId: $event->eventId,
            brandId: $event->brandId,
            registrantUserId: $event->registrantUserId,
            grants: $event->grants,
            amendmentId: $event->amendmentId,
        );
    }
}
