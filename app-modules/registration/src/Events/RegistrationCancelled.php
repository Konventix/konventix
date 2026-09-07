<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * The contractual act was cancelled by organizer or registrant (SPEC.md
 * "Public contract" table). Emitted by
 * `Konventix\Registration\Actions\CancelRegistration`.
 *
 * `cancelledByUserId` is null where nobody acted: ADR-0057 point 4's
 * unpaid-reversal sweep cancels the order after the repayment deadline, and
 * the cancellation it triggers here has no person behind it. A consumer that
 * asks "did the registrant cancel this themselves?" — `MailOnRegistrationCancelled`
 * does — reads null as "not the registrant".
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class RegistrationCancelled
{
    public function __construct(
        public readonly string $registrationId,
        public readonly ?string $cancelledByUserId,
        public readonly string $reasonCode,
        public readonly string $cancelledAt,
    ) {}
}
