<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

use Konventix\Admission\Enums\HubAccessEndReason;

/**
 * A participant's hub access went true→false (design §4.3). `reason` is a
 * `HubAccessEndReason` value: `day_ended` when a once-per-day window
 * closed (D-HUB-01) — the next day's accepted scan re-admits them —
 * `concluded`/`cancelled` when the event did, `revoked` when the
 * admission that let them in was.
 *
 * Payload is ULIDs and scalars only (ADR-0004); the enum travels as its
 * backing string so consumers in other modules need not import it.
 *
 * @see HubAccessEndReason
 */
final readonly class ParticipantHubAccessEnded
{
    public function __construct(
        public string $userId,
        public string $eventId,
        public string $brandId,
        public string $reason,
        public string $endedAt,
    ) {}
}
