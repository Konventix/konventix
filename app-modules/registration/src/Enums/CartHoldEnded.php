<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * Why a cart stopped holding a registration's spot (#220).
 *
 * `CancelPendingRegistrationForCart` used to hard-code `cart_expired`
 * whatever the trigger had been, and `CancelPendingRegistrationOnCartLineGone`
 * never told it which of the two Commerce events it was reacting to — so a
 * buyer who deliberately took a line out of a cart that was still valid for
 * another twenty minutes was mailed that their reservation had *expired*.
 * This enum is what the listener now passes down, one case per Commerce
 * event, and its value is the `reason_code` `CancelRegistration` writes and
 * the `cancelReason` the cancellation mail branches on.
 *
 * Deliberately a Registration enum rather than a Commerce one: the codes are
 * this module's own vocabulary — `registrant` and `operator` are the two
 * others, and neither has anything to do with a cart — and the module
 * boundary carries events, not enums (ADR-0004).
 */
enum CartHoldEnded: string
{
    /**
     * The cart ran past `Cart::LIFETIME_MINUTES` with the line still in it
     * (`Konventix\Commerce\Events\CartLineExpired`). The reservation lapsed
     * before the money arrived, which is what the mail has always said.
     */
    case Expired = 'cart_expired';

    /**
     * The buyer took the line out of the cart themselves and let #219's undo
     * window close on it (`Konventix\Commerce\Events\CartLineRemoved`). The
     * cart was valid throughout; nothing expired.
     */
    case Removed = 'cart_line_removed';
}
