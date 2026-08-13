<?php

declare(strict_types=1);

namespace Konventix\Content\Events;

/**
 * An announcement went live (D-CNT-08).
 *
 * **Content declares intent only.** `urgency` and `audience` say what this
 * announcement *is*; Messaging alone maps that to channels and timing, where
 * urgent bypasses digests and quiet hours. Content must never decide delivery
 * itself, or the same announcement would mean different things depending on
 * which module rendered it.
 *
 * Emitted alongside `ContentEntryPublished` rather than instead of it: an
 * announcement is still an entry, and a consumer listing content should not
 * have to know about notification semantics to see it.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class AnnouncementPublished
{
    public function __construct(
        public string $entryId,
        public string $brandId,
        public ?string $eventId,
        public string $title,
        public string $urgency,
        public string $audience,
        public string $publishedAt,
    ) {}
}
