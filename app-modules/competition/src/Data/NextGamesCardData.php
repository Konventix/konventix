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
 * and null otherwise; the card then reloads the hub's prop on that interval
 * (hub design §7.1). The URLs arrive here because a hub component imports
 * no route helper (`tests/Feature/HubComponentBoundaryTest.php`).
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
        public readonly ?int $pollSeconds,
    ) {}
}
