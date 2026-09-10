<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners;

use Konventix\Platform\Modules\Concerns\ShortCircuitsInactiveModule;
use Konventix\Registration\Events\RegistrationAmended;
use Konventix\Resources\Actions\FulfilResourceClaims;

/**
 * The delta counterpart to `FulfilClaimsOnRegistrationConfirmed` (ADR-0036):
 * grants were added to an already-confirmed registration, so fulfil exactly
 * those. The payload's `grants` are only the *added* grants and
 * `amendmentId` scopes the idempotency check, so this fires safely against
 * a registration that already has bookings from its confirmation, and a
 * replay of the same amendment books nothing new.
 *
 * Gated on the payload's brand for the same reason as its sibling: an
 * amendment can be confirmed from a webhook with no brand in context.
 * Registered in `ResourcesServiceProvider::registerListeners()`.
 */
final class FulfilClaimsOnRegistrationAmended
{
    use ShortCircuitsInactiveModule;

    public function __construct(
        private readonly FulfilResourceClaims $action,
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

        $this->action->handle(
            registrationId: $event->registrationId,
            eventId: $event->eventId,
            brandId: $event->brandId,
            grants: $event->grants,
            amendmentId: $event->amendmentId,
        );
    }
}
