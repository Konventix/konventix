<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Commerce\Events\CartLineExpired;
use Konventix\Commerce\Events\CartLineRemoved;
use Konventix\Registration\Actions\CancelPendingRegistrationForCart;

/**
 * ADR-0047 point 3: a cart holds a still-pending registration's spot from
 * the moment its settlement request is placed. When that hold goes away —
 * the reservation expired, or the person removed the line themselves — the
 * registration it was holding for is cancelled, releasing whatever quota
 * units it consumed. Registered twice in
 * `RegistrationServiceProvider::registerListeners()`, once per event, both
 * bound to this same method.
 *
 * `Konventix\Commerce\Events\CartLineExpired`/`CartLineRemoved` are the
 * Commerce classes this module imports here, allowed because events are
 * the public contract between modules (ADR-0004) — the sanctioned
 * exception now runs in both directions (ADR-0047 "Consequences"). Only a
 * `registration`-kind line concerns this module; a `product` line's stock
 * release is Commerce's own business.
 *
 * Core module (never gated) — no `ShortCircuitsInactiveModule`, same
 * reasoning as `RecordPaymentOnOrderPaid`.
 */
final class CancelPendingRegistrationOnCartLineGone
{
    private const string KIND_REGISTRATION = 'registration';

    public function __construct(
        private readonly CancelPendingRegistrationForCart $action,
    ) {}

    public function handle(CartLineExpired|CartLineRemoved $event): void
    {
        if ($event->kind !== self::KIND_REGISTRATION) {
            return;
        }

        $this->action->handle(
            brandId: $event->brandId,
            registrationId: $event->refId,
        );
    }
}
