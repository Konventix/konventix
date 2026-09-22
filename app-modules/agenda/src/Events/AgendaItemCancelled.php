<?php

declare(strict_types=1);

namespace Konventix\Agenda\Events;

/**
 * A programme entry was called off (design §7). D-AGD-08: the row stays
 * and the public page keeps showing it, struck through — this event is
 * how the people who are not looking at the page get told.
 *
 * Nothing consumes it in 0.9.0. It exists so that Messaging can one day
 * say "Lötkurs 15:00 fällt aus" without Agenda ever learning what a
 * notification is.
 *
 * Payload is ULIDs and scalars only (ADR-0004). `reason` is the organizer's
 * own words ("Referent krank") and may be absent.
 */
final readonly class AgendaItemCancelled
{
    public function __construct(
        public string $agendaItemId,
        public string $eventId,
        public string $brandId,
        public ?string $reason,
    ) {}
}
