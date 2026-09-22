<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Commerce\Events\CartLineExpired;
use Konventix\Commerce\Events\CartLineRemoved;
use Konventix\Registration\Actions\CancelPendingRegistrationForCart;
use Konventix\Registration\Enums\CartHoldEnded;

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
 * **Which of the two it was travels with the call (#220).** The method
 * still handles both events, but it no longer throws the distinction away:
 * `CartLineExpired` becomes `CartHoldEnded::Expired` and `CartLineRemoved`
 * becomes `CartHoldEnded::Removed`, and that is what ends up as the
 * registration's `reason_code` and as the sentence in the cancellation
 * mail. A buyer who took a line out of a cart that was still valid used to
 * be told their reservation had expired.
 *
 * Since #219, `CartLineRemoved` no longer arrives on the click: Commerce
 * soft-removes the line and emits only once the buyer's undo window has
 * closed without an undo. Nothing changes on this side — the event still
 * means "this line is gone for good" — but it is why the cancellation that
 * follows is now the buyer's settled decision rather than a mis-click.
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
            reason: $event instanceof CartLineExpired
                ? CartHoldEnded::Expired
                : CartHoldEnded::Removed,
        );
    }
}
