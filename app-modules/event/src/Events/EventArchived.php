<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * The event is closed for all operations (SPEC.md "Public contract" table).
 * Emitted by `Konventix\Event\Actions\ArchiveEvent`.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class EventArchived
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $brandId,
        public readonly string $archivedAt,
    ) {}
}
