<?php

declare(strict_types=1);

namespace Konventix\Identity\Events;

/**
 * Contract event (SPEC.md): two accounts belonging to the same person were
 * combined into one (#81, `Actions\MergeUserAccounts`). `survivingUserId`
 * keeps its ULID and stays reachable; `mergedUserId` is now a tombstone
 * (`AccountState::Merged`) with its credentials cleared.
 *
 * This event reports only that Identity's own part is done — the login
 * methods. It is deliberately NOT a signal that every trace of the merged
 * account has moved: registrations, orders, admissions and consent records
 * are owned by other modules (ADR-0004) and this event is the seam for them
 * to listen on, exactly the way `UserAnonymized`'s docblock already says for
 * anonymization. No listener exists yet in Registration, Commerce, Admission
 * or Compliance — seeing this event fire is not evidence any of the four
 * moved anything. See `Actions\MergeUserAccounts`'s docblock for the full
 * account of what this issue's slice does and does not reach.
 */
final class AccountsMerged
{
    public function __construct(
        public readonly string $survivingUserId,
        public readonly string $mergedUserId,
        /**
         * The operator account that performed the merge — an authenticated,
         * permissioned action (`Permission::ManageUsers`), never self-service
         * (see the Action's docblock for why). Kept for the audit trail a
         * decision this hard to undo deserves.
         */
        public readonly string $performedByUserId,
        public readonly string $mergedAt,
    ) {}
}
