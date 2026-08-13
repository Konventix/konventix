<?php

declare(strict_types=1);

namespace Konventix\Content\Events;

/**
 * An entry went live. Emitted by `Konventix\Content\Actions\PublishEntry`.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class ContentEntryPublished
{
    public function __construct(
        public string $entryId,
        public string $brandId,
        public ?string $eventId,
        public string $type,
        public string $slug,
        public string $title,
        public string $audience,
        public string $publishedAt,
    ) {}
}
