<?php

declare(strict_types=1);

namespace Konventix\Commerce\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A payment provider refused a refund, in its own words.
 *
 * One exception type for every adapter (#236 fix round 1, review finding 5).
 * Each SDK raises from outside the `RuntimeException` hierarchy —
 * `Stripe\Exception\ApiErrorException`, `Mollie\Api\Exceptions\ApiException`
 * and `UnzerSDK\Exceptions\UnzerApiException` all extend `\Exception` — so
 * catching them one by one in the console meant the operator who refunded a
 * Mollie or Unzer order got the same 500 and the same stack trace #236 was
 * filed about, and it put a `use Stripe\Exception\…` in a controller, the
 * only place outside `src/Settlement/Stripe/` that named an SDK. Translation
 * belongs at the seam: the adapter knows its SDK, the console knows only that
 * the provider said no.
 *
 * `RuntimeException` like every other refusal this module makes
 * (`RefundBlockedByCheckInException`, "only a paid order can be refunded"),
 * so a caller that already handles those keeps working. The console catches
 * this one **first**, because the answer differs: an Action's refusal means
 * nothing moved, while a provider error leaves open whether the money went
 * back — so the message asks for a reconciliation rather than a retry.
 */
class ProviderRefusedRefundException extends RuntimeException
{
    private function __construct(
        public readonly string $provider,
        public readonly bool $isConfigurationProblem,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * The provider considered the request and said no — "charge has already
     * been refunded", "amount exceeds the remaining balance". The message is
     * the provider's own: it is the only text that says what actually
     * happened, and the operator needs it to reconcile.
     */
    public static function refused(string $provider, string $message, ?Throwable $previous = null): self
    {
        return new self($provider, false, $message, $previous);
    }

    /**
     * The provider never got as far as considering it: a rejected or absent
     * API key (fix round 1, review nit 7). Told apart because
     * "the payment provider refused the refund" would send an operator
     * hunting for a payment problem that is really a settings problem.
     */
    public static function misconfigured(string $provider, string $message, ?Throwable $previous = null): self
    {
        return new self($provider, true, $message, $previous);
    }
}
