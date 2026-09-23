<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * An announced competition was taken back off the public pages:
 * `Published → Draft` (design §4, §14 "Unpublishing a competition tells
 * nobody").
 *
 * A consumer that projected the competition on `CompetitionPublished` removes
 * it here — Agenda's programme read model is the first. Only emitted when the
 * transition succeeded; a refused unpublish announces nothing.
 *
 * Payload is ULIDs and scalars only (ADR-0004); `unpublishedAt` is an
 * ISO-8601 string. There is no `disciplineId` and no `status`: the status is
 * always `draft` after this move, and nothing that forgets a competition
 * needs its discipline.
 */
final readonly class CompetitionUnpublished
{
    public function __construct(
        public string $competitionId,
        public string $eventId,
        public string $brandId,
        public string $unpublishedAt,
    ) {}
}
