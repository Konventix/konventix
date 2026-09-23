<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * `/events/{slug}/tournaments` (design §10): every competition of the event
 * that is not a draft. `pollSeconds` is set while one of them is `Running`
 * and null otherwise (§10 "Freshness").
 */
#[TypeScript]
final class EventTournamentsPageData extends Data
{
    /**
     * @param  array<int, TournamentCardData>  $competitions
     */
    public function __construct(
        public readonly EventRefData $event,

        /** @var array<int, TournamentCardData> */
        #[DataCollectionOf(TournamentCardData::class)]
        public readonly array $competitions,
        public readonly ?int $pollSeconds,
    ) {}
}
