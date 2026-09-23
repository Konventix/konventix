<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\NextGameNudge;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the hub's *Nächste Spiele* card (design §10a): one of the
 * viewer's open matches at the running event.
 *
 * `bracket` and `round` rather than a finished label: the card names the
 * round the way the match page's heading does, with the same
 * `sectionLabel()`, so the two cannot drift. `opponentName` is the other
 * side's team name, several joined for a match with more than two sides;
 * null with `awaitingOpponent` while the slot waits on an undecided match,
 * null without it for a bye. `scheduledAt` is an ISO-8601 instant — this
 * module stores match times as instants (§10a "Time").
 */
#[TypeScript]
final class NextGameData extends Data
{
    public function __construct(
        public readonly string $matchId,
        public readonly string $competitionName,
        public readonly ?string $bracket,
        public readonly int $round,
        public readonly ?string $opponentName,
        public readonly bool $awaitingOpponent,
        public readonly ?string $scheduledAt,
        public readonly ?NextGameNudge $nudge,
        public readonly string $matchUrl,
    ) {}
}
