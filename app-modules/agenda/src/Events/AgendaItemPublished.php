<?php

declare(strict_types=1);

namespace Konventix\Agenda\Events;

/**
 * A draft programme entry became visible on the public page (design §7).
 *
 * Nothing consumes this in 0.9.0. It exists so that Messaging can one day
 * tell a participant that the workshop they were waiting for is on the
 * programme, without Agenda ever learning what a notification is — and so
 * that the presence work has something to react to later.
 *
 * Payload is ULIDs and scalars only (ADR-0004); the instants are ISO-8601
 * strings for the same reason, and either may be null because an item may
 * be open at either end (D-AGD-04).
 */
final readonly class AgendaItemPublished
{
    public function __construct(
        public string $agendaItemId,
        public string $eventId,
        public string $brandId,
        public string $title,
        public ?string $startsAt,
        public ?string $endsAt,
    ) {}
}
