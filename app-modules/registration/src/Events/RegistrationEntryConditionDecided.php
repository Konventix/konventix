<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * Somebody judged a declaration — verified, refused or waived it (spec §8),
 * at the console or at the door.
 *
 * A refusal records a fact and nothing else: it never revokes the admission
 * and never reaches Commerce (T-8, ADR-0005). `reasonCode` is one of the
 * bounded codes, never a sentence about a person.
 *
 * `state` and `decidedVia` are the two metric labels
 * (`entry_condition_decisions_total{state, source}`) and are bounded enum
 * values for exactly that reason.
 *
 * Scalars only (ADR-0004).
 */
final readonly class RegistrationEntryConditionDecided
{
    public function __construct(
        public string $assertionId,
        public string $registrationId,
        public string $entryConditionKey,
        public string $brandId,
        public string $state,
        public ?string $decidedByUserId,
        public string $decidedVia,
        public ?string $reasonCode,
        public string $decidedAt,
    ) {}
}
