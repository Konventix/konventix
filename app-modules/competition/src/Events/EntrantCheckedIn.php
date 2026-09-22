<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * A team was checked in for a competition (design §6, §12).
 *
 * One event for both ways it happens — the captain on the participant page
 * inside the self-service window, or the desk, which may always do it
 * whatever the mode. `viaSelfService` tells them apart for anybody auditing
 * how a team got in without changing what the fact means; every consumer that
 * only cares *that* the roster is ready can ignore it.
 *
 * `memberUserIds` is the roster at the moment of check-in, oldest member
 * first — the same shape `EntrantRegistered` and `EntrantWithdrawn` carry,
 * and what GameOps (#179) needs to place players. Read before the write, like
 * every other roster on an event here: the desk may move somebody a second
 * later.
 *
 * There is deliberately **no undo counterpart**. Design §12 names this event
 * and no other around check-in, and `UndoCheckIn` exists for the desk to
 * correct a mis-scan seconds later — a second contract event for "never
 * mind" would be consumed as a fact in its own right. Worth revisiting if a
 * consumer ever provisions on `EntrantCheckedIn`.
 *
 * ULIDs and scalars only (ADR-0004).
 */
final readonly class EntrantCheckedIn
{
    /**
     * @param  list<string>  $memberUserIds  the roster as it stood at check-in, oldest member first
     */
    public function __construct(
        public string $entrantId,
        public string $competitionId,
        public string $competitionTeamId,
        public string $eventId,
        public string $brandId,
        public array $memberUserIds,
        public string $checkedInAt,
        public bool $viaSelfService,
    ) {}
}
