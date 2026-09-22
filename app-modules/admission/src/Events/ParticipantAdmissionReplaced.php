<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * The admission behind a participant's hub access changed, and their access
 * never lapsed (D-HUB-01, design §4.2 survivor rule). A person can hold
 * several admissions for one event — a crew badge beside a bought ticket —
 * and revoking one of them is not losing the event: `ProjectEventPresence`
 * re-points the presence row at an admission of theirs that still stands
 * and emits this instead of `ParticipantHubAccessEnded`.
 *
 * It exists because consumers *mirror* the admission id, and a mirror of a
 * revoked id is worse than none: Platform's `platform_hub_access` carries
 * `admission_id`, and Resources builds the hub's "Mein Sitzplatz" link out
 * of it — without this event that link would point at the admission that
 * was just taken away. Nobody may derive access from this event: access is
 * unchanged by definition, which is the whole point of it.
 *
 * `admissionId` is the **surviving** admission, the one the row now names.
 *
 * Payload is ULIDs, ISO-8601 strings and scalars only (ADR-0004).
 */
final readonly class ParticipantAdmissionReplaced
{
    public function __construct(
        public string $userId,
        public string $eventId,
        public string $brandId,
        public string $admissionId,
        public string $replacedAt,
    ) {}
}
