<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * `…/{competition}/teams/{team}` (design §10): the roster and the team's
 * matches across the event, in every competition that is not a draft.
 */
#[TypeScript]
final class TeamPageData extends Data
{
    /**
     * @param  list<string>  $members
     * @param  array<int, TeamMatchData>  $matches
     */
    public function __construct(
        public readonly EventRefData $event,
        public readonly CompetitionDetailData $competition,
        public readonly string $teamName,
        /** @var list<string> display names, oldest member first */
        public readonly array $members,

        /** @var array<int, TeamMatchData> */
        #[DataCollectionOf(TeamMatchData::class)]
        public readonly array $matches,
        public readonly ?int $pollSeconds,
    ) {}
}
