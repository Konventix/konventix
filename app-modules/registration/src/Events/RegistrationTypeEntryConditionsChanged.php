<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * The set of conditions a registration type demands has changed (spec §8,
 * D-REG-19).
 *
 * `entryConditionIds` is the whole new set, in render order — a full state,
 * not a delta, because a consumer that missed one event must still end up
 * right.
 *
 * Nothing retroactive follows from it: existing registrations keep their
 * assertion snapshots, a newly added condition is *not asserted* for them
 * and writes no row, and a removed one leaves its historic assertions
 * standing (spec §4.2).
 *
 * Scalars only (ADR-0004).
 */
final readonly class RegistrationTypeEntryConditionsChanged
{
    /**
     * @param  list<string>  $entryConditionIds
     */
    public function __construct(
        public string $registrationTypeId,
        public string $brandId,
        public array $entryConditionIds,
    ) {}
}
