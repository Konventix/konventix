<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * A competition as it stands right now, restated for backfill.
 * `competition:announce-competitions` emits it once for every competition of
 * every brand, so that a read model built after competitions were published
 * can learn them. The first consumer is Agenda's programme read model
 * (`agenda_competitions`, timetable design §4.5, #331).
 *
 * This is its own event rather than a replay of `CompetitionPublished`.
 * That one is a lifecycle announcement: "this competition has just gone
 * public". Its future consumers (Messaging's notices, the metrics) would
 * re-notify and re-count every competition on each run. The same reasoning
 * produced `SocialIdentityAnnounced` and `RegistrationStandingAnnounced`.
 * **Only a projection may ever listen to it.** It is not auditable.
 *
 * `announced` is Competition's own answer to "is this publicly announced
 * right now and not taken back": `status` is not `draft` **and**
 * `published_at` is set. A draft (never published, or unpublished since)
 * announces `false`, so a consumer that projected it earlier unlearns it.
 * A competition cancelled straight from `Draft` announces `false` as well,
 * because `published_at` is null and nobody ever saw it. One cancelled
 * after publishing announces `true` with status `cancelled`, which is what
 * `CompetitionCancelled` would have told the consumer.
 *
 * Payload is ULIDs and scalars only (ADR-0004); `startsAt` is an ISO-8601
 * string, formatted as `CompetitionPublished` formats it.
 */
final readonly class CompetitionAnnounced
{
    public function __construct(
        public string $competitionId,
        public string $eventId,
        public string $brandId,
        public string $name,
        public string $slug,
        public string $status,
        public ?string $startsAt,
        public bool $announced,
    ) {}
}
