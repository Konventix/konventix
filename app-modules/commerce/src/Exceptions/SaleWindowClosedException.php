<?php

declare(strict_types=1);

namespace Konventix\Commerce\Exceptions;

use RuntimeException;

/**
 * Thrown by `PlaceRegistrationLineInCart` when the settlement request it is
 * asked to turn into a cart line declares a sales window that is not open
 * right now (#218, #216 draft i).
 *
 * The request only carries a window when Registration says it must honour
 * one — a fresh registration someone is buying now (fix round 1). A waitlist
 * conversion, a confirmation after approval and a priced amendment all send
 * nulls and are never refused here: Registration is the authority on who may
 * register.
 *
 * `CreateOrderOnSettlementRequested` is a plain synchronous listener, so this
 * throw propagates into the emitting Action's own transaction and rolls it
 * back — nothing placed, which is the right outcome. It is typed so
 * `SubmitRegistration` can tell that refusal apart from a real failure and
 * turn it into a form error rather than a 500. (It does so by re-evaluating
 * the window rather than importing this class: ADR-0004 forbids Registration
 * depending on Commerce.)
 *
 * The checkout does not re-check the window at all — see `PlaceOrderFromCart`
 * for why a line placed during the sale stays payable within its cart hold.
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
