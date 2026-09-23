<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * `/events/{slug}/tournaments/{competition}` (design §10): description and
 * rules, the entered teams with their rosters, the interested list, the
 * bracket (canvas and table from the one `BracketData`) or the leaderboard,
 * the standings, and the viewer's calls to action. The schedule is the
 * bracket's own `scheduledAt`s, so it is not sent twice.
 *
 * `pollSeconds` is 30 while the competition is `Running` and null otherwise
 * (§10 "Freshness").
 */
#[TypeScript]
final class CompetitionPageData extends Data
{
    /**
     * @param  array<int, EnteredTeamData>  $teams
     * @param  array<int, InterestedPersonData>  $interested
     * @param  array<int, DeskStandingData>  $standings
     */
    public function __construct(
        public readonly EventRefData $event,
        public readonly CompetitionDetailData $competition,

        /** @var array<int, EnteredTeamData> */
        #[DataCollectionOf(EnteredTeamData::class)]
        public readonly array $teams,

        /** @var array<int, InterestedPersonData> */
        #[DataCollectionOf(InterestedPersonData::class)]
        public readonly array $interested,
        public readonly ?BracketData $bracket,

        /** @var array<int, DeskStandingData> */
        #[DataCollectionOf(DeskStandingData::class)]
        public readonly array $standings,
        public readonly CompetitionViewerData $viewer,
        public readonly ?int $pollSeconds,
    ) {}
}
