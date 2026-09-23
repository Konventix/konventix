<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The props of the hub's *Nächste Spiele* card (design §10a): at most
 * `NextGamesCard::ROWS` of the viewer's open matches, soonest first, and
 * whether there are more than that.
 *
 * `pollSeconds` is set while one of the viewer's competitions is `Running`
 * and null otherwise; the card then fetches `nextGamesUrl` — the same shape
 * as JSON, from `NextGamesController` — on that interval (hub design §7.1),
 * so the rest of the hub is never rebuilt for it. The URLs arrive here
 * because a hub component imports no route helper
 * (`tests/Feature/HubComponentBoundaryTest.php`).
 *
 * `eventTimezone` is the event's IANA zone (ADR-0027): the rows' instants
 * are shown on the hall's clock. Null when the projection has not heard of
 * it, and the card then uses the reader's zone.
 */
#[TypeScript]
final class NextGamesCardData extends Data
{
    /**
     * @param  array<int, NextGameData>  $games
     */
    public function __construct(
        /** @var array<int, NextGameData> */
        #[DataCollectionOf(NextGameData::class)]
        public readonly array $games,
        public readonly bool $hasMore,
        public readonly string $myTournamentsUrl,
        public readonly string $nextGamesUrl,
        public readonly ?string $eventTimezone,
        public readonly ?int $pollSeconds,
    ) {}
}
