<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;

/**
 * The payload behind the two messages §5.4 owes people: "you have been put on
 * a team" and "your team did not make the field".
 *
 * A typed object rather than a free-form array (ADR-0009), and names rather
 * than ULIDs — a person reading a mail needs to know which tournament and
 * which team, not which row. The names come from the models the Action
 * already has in hand; the *people's* names, where a template ever needs
 * them, come from `ParticipantProfile` and never from Identity.
 */
final class EntrantNoticeData extends Data
{
    public function __construct(
        public readonly string $competitionName,
        public readonly string $teamName,
        public readonly string $detail,
    ) {}
}
