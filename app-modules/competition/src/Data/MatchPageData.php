<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * `…/{competition}/matches/{match}` (design §10): sides, scores,
 * `scheduled_at` for everyone; the history (§8.6) and report / confirm /
 * dispute only for a member of a side — §8.6 shows the history "to the sides
 * and the desk".
 */
#[TypeScript]
final class MatchPageData extends Data
{
    /**
     * @param  array<int, MatchHistoryRowData>  $history
     */
    public function __construct(
        public readonly EventRefData $event,
        public readonly CompetitionDetailData $competition,
        public readonly BracketMatchData $match,

        /** @var array<int, MatchHistoryRowData> oldest first; empty unless the viewer is on a side */
        #[DataCollectionOf(MatchHistoryRowData::class)]
        public readonly array $history,
        public readonly MatchViewerData $viewer,
        public readonly ?int $pollSeconds,
    ) {}
}
