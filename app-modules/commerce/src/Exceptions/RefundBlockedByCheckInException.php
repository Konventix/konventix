<?php

declare(strict_types=1);

namespace Konventix\Commerce\Exceptions;

use RuntimeException;

/**
 * Thrown when a refund is refused because the settled registration has at
 * least one checked-in admission (ADR-0037, D-COM-15 — the user's override of
 * the report-only recommendation).
 *
 * ADR-0037 is explicit that this is "an explicit refusal with a reason, not a
 * silent failure": someone attended, so the money is not automatically
 * returnable, and an operator who decides otherwise should have to say so
 * deliberately rather than have the system quietly comply.
 *
 * Cancellation is deliberately **not** blocked — only the money flow.
 */
final class RefundBlockedByCheckInException extends RuntimeException
{
    public static function forRegistration(string $registrationId): self
    {
        return new self(sprintf(
            'Registration [%s] has at least one checked-in admission, so its order cannot be '
            .'refunded (ADR-0037). Cancelling the registration is still possible; returning the '
            .'money is a deliberate act that needs a different decision.',
            $registrationId,
        ));
    }
}
