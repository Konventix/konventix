<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * A registrant declared that they meet an entry condition (spec §8).
 *
 * One per condition the type demanded at submission, whatever the
 * registration's quantity (D-REG-20).
 *
 * Carries the condition's `key` beside its ULID: the key is what the door
 * and a log line read, the ULID is what a Compliance policy binding points
 * at. `verification` is the bounded enum value, and the only one of these
 * that ever becomes a metric label (T-11) — the key never does.
 *
 * The wording that was actually shown stays on the assertion row, not here:
 * an event payload is a notification, not the evidence.
 *
 * Scalars only (ADR-0004).
 */
final readonly class RegistrationEntryConditionAsserted
{
    public function __construct(
        public string $assertionId,
        public string $registrationId,
        public string $entryConditionId,
        public string $entryConditionKey,
        public string $brandId,
        public string $subjectUserId,
        public string $verification,
        public string $assertedAt,
    ) {}
}
