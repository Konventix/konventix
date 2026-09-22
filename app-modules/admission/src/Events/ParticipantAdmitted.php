<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * A signed-in participant's hub access went false→true: their first
 * accepted scan at the event, or the first scan of a new day on a
 * `once_per_day` event (D-HUB-01, design §4.3). This is what opens `/live`
 * for them — Platform projects it into its own `hub_access` read model and
 * never reads `admission_event_presence` (ADR-0004).
 *
 * `windowEndsAt` is the instant the access window closes, null when it
 * stays open until the event concludes. `dayLabel`, `eventName` and
 * `eventSlug` are display data for the hub pill, nullable because an event
 * may have no day calendar and the summary may not be projected yet.
 *
 * Payload is ULIDs, ISO-8601 strings and scalars only (ADR-0004).
 */
final readonly class ParticipantAdmitted
{
    public function __construct(
        public string $userId,
        public string $eventId,
        public string $brandId,
        public string $admissionId,
        public string $admittedAt,
        public ?string $windowEndsAt,
        public ?string $dayLabel,
        public ?string $eventName,
        public ?string $eventSlug,
    ) {}
}
