<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\CheckInMode;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The tournament desk's one page (design §9, D-CMP-21, ADR-0009).
 *
 * Everything the desk runs a competition from, read once per visit by
 * `Desk\TournamentDesk`: the seed order, the drawn matches round by round,
 * the **open disputes** it has to decide, the standings, and for a
 * leaderboard the verification queue. `powers` says which of the four desk
 * capabilities the viewer holds, so a desk volunteer is not offered a button
 * the server would refuse — the server refuses regardless.
 *
 * A competition runs one stage in this slice (§7.3), so `stage` is the first
 * and only one, and null for a competition that somehow has none.
 */
#[TypeScript]
final class TournamentDeskData extends Data
{
    /**
     * @param  array<int, DeskEntrantData>  $field
     * @param  array<int, DeskMatchData>  $matches
     * @param  array<int, DeskDisputeData>  $disputes
     * @param  array<int, DeskStandingData>  $standings
     * @param  array<int, DeskScoreEntryData>  $queue
     */
    public function __construct(
        public readonly string $competitionId,
        public readonly string $name,
        public readonly CompetitionStatus $status,
        /** The anchor every match time is set against (D-CMP-21). */
        public readonly ?string $startsAt,
        public readonly CheckInMode $checkInMode,
        public readonly ResultSubmissionMode $resultSubmissionMode,
        /** How many of the field the check-in desk has ticked off. */
        public readonly int $checkedIn,
        public readonly ?DeskStageData $stage,

        /** @var array<int, DeskEntrantData> the seedable field, in seed order */
        #[DataCollectionOf(DeskEntrantData::class)]
        public readonly array $field,

        /** @var array<int, DeskMatchData> */
        #[DataCollectionOf(DeskMatchData::class)]
        public readonly array $matches,

        /** @var array<int, DeskDisputeData> */
        #[DataCollectionOf(DeskDisputeData::class)]
        public readonly array $disputes,

        /** @var array<int, DeskStandingData> */
        #[DataCollectionOf(DeskStandingData::class)]
        public readonly array $standings,

        /** @var array<int, DeskScoreEntryData> */
        #[DataCollectionOf(DeskScoreEntryData::class)]
        public readonly array $queue,
        public readonly DeskPowersData $powers,
    ) {}
}
