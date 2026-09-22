<?php

declare(strict_types=1);

namespace Konventix\Agenda\Events;

/**
 * An already published programme entry moved in time (design §7). A draft
 * that is edited before anybody could see it moves nothing and emits
 * nothing — this is news only once the old time was on the page.
 *
 * Nothing consumes it in 0.9.0. It exists so that Messaging can one day
 * tell the people who planned an afternoon around "Lötkurs 15:00" that it
 * is now at 17:00, without Agenda ever learning what a notification is.
 *
 * Payload is ULIDs and scalars only (ADR-0004); the instants are the new
 * ones, ISO-8601 and nullable (D-AGD-04).
 */
final readonly class AgendaItemRescheduled
{
    public function __construct(
        public string $agendaItemId,
        public string $eventId,
        public string $brandId,
        public ?string $startsAt,
        public ?string $endsAt,
    ) {}
}
