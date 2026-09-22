<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * A team entered a competition.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 *
 * `memberUserIds` and `state` are **additive** (design §5, #297): every
 * argument before them kept its name and position, so a consumer written
 * against the earlier shape is untouched.
 *
 * The roster is on the payload now, and the earlier docblock's reason for
 * leaving it off ("a consumer that needs it is asking the wrong module") did
 * not survive contact with §5.4: an entry can be `incomplete`, the organizer
 * resolving it notifies exactly these people through Messaging, and GameOps
 * needs the same list per side to place players on a server (which is why
 * `MatchScheduled` grew it too). Everything here is still a ULID — the names
 * come from `ParticipantProfile`, which is this module's own read model.
 *
 * `state` distinguishes the two ways an entry begins (§5.4): `registered`
 * when the roster already equals the discipline's team size, `incomplete`
 * when it is short and holding a slot until it is filled or declined.
 */
final readonly class EntrantRegistered
{
    /**
     * @param  list<string>  $memberUserIds  the roster at the moment of entry, oldest member first
     */
    public function __construct(
        public string $entrantId,
        public string $competitionId,
        public string $competitionTeamId,
        public string $eventId,
        public string $brandId,
        public string $registeredAt,
        public array $memberUserIds = [],
        public string $state = 'registered',
    ) {}
}
