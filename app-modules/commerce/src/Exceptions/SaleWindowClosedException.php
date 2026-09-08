<?php

declare(strict_types=1);

namespace Konventix\Commerce\Exceptions;

use RuntimeException;

/**
 * Thrown by `PlaceRegistrationLineInCart` when the settlement request it is
 * asked to turn into a cart line declares a sales window that is not open
 * right now (#218, #216 draft i).
 *
 * Typed rather than a raw `RuntimeException` because the caller is a
 * listener, not a controller: a queued replay of a stale settlement request
 * must fail loudly and land in `failed_jobs` with a name that says what
 * happened, instead of quietly creating a line the checkout would refuse
 * anyway.
 *
 * The checkout's own refusal is `CartCheckoutRefusedException::saleWindowClosed()`
 * — a different class because `CartController::checkout()` catches exactly
 * that one and turns it into a 409 for the payer.
 */
final class SaleWindowClosedException extends RuntimeException
{
    public static function forRegistration(string $registrationId, ?string $saleStartsAt, ?string $saleEndsAt): self
    {
        return new self(sprintf(
            'Registration [%s] is not on sale (window %s to %s).',
            $registrationId,
            $saleStartsAt ?? 'open',
            $saleEndsAt ?? 'open',
        ));
    }
}
