<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The props of the hub's *Meine Competitions* card (design §10a): at most
 * `MyCompetitionsCard::ROWS` of the viewer's entries at the running event,
 * and whether there are more.
 *
 * `tournamentsUrl` is the event's tournament index, which the empty state
 * points at; it is empty when this module does not know the event's slug.
 */
#[TypeScript]
final class MyCompetitionsCardData extends Data
{
    /**
     * @param  array<int, MyCompetitionEntryData>  $entries
     */
    public function __construct(
        /** @var array<int, MyCompetitionEntryData> */
        #[DataCollectionOf(MyCompetitionEntryData::class)]
        public readonly array $entries,
        public readonly bool $hasMore,
        public readonly string $myTournamentsUrl,
        public readonly string $tournamentsUrl,
    ) {}
}
