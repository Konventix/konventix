<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * A team left the field — pulled by its captain, pulled by the organizer,
 * declined at signup close because the roster never filled up, or
 * disqualified (design §5.4, §5.5).
 *
 * One event for all four because what consumers act on is the same in every
 * case: the slot is free, the people on `memberUserIds` are no longer
 * playing, and anything provisioned for them can be torn down. `reason`
 * carries `WithdrawalReason`'s scalar value for the ones that need to tell
 * them apart — a declined roster is news the team should hear gently, a
 * disqualification is not.
 *
 * `finalState` says which of the two terminal states the entrant landed in:
 * `withdrawn` for the first three, `disqualified` for the fourth. They are
 * different states on `EntrantState` and a consumer that collapses them
 * would report a disqualified team as having politely pulled out.
 *
 * ULIDs and scalars only (ADR-0004).
 */
final readonly class EntrantWithdrawn
{
    /**
     * @param  list<string>  $memberUserIds  the roster as it stood when the entrant left
     */
    public function __construct(
        public string $entrantId,
        public string $competitionId,
        public string $competitionTeamId,
        public string $eventId,
        public string $brandId,
        public array $memberUserIds,
        public string $reason,
        public string $finalState,
        public string $withdrawnAt,
    ) {}
}
