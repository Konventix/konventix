<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Stripe;

use RuntimeException;
use Throwable;

/**
 * Stripe no longer holds the checkout session a payment named — its own
 * "No such checkout.session" answer (`resource_missing`), translated here so
 * nothing outside `Settlement/Stripe/` needs to recognise
 * `Stripe\Exception\InvalidRequestException` to react to it (#286).
 *
 * This is a definite answer, not a transport failure: the session is gone
 * and nothing was paid through it. That is different from every other
 * `InvalidRequestException` and from an outage or a rejected key, both of
 * which stay untranslated ("we do not know" must never read as "not paid",
 * the same rule `StripeSettlementAdapter::hostedPaymentState()` applies to
 * `HostedPaymentState::Unreachable`) — only the one Stripe error code this
 * class names becomes this exception; everything else keeps propagating as
 * whatever the SDK threw.
 */
final class StripeSessionNotFoundException extends RuntimeException
{
    public static function forSession(string $sessionId, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Stripe no longer has checkout session [%s].', $sessionId),
            previous: $previous,
        );
    }
}
