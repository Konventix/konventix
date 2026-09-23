<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An open dispute the desk has to decide (design §8.4, §14 "the desk hears
 * about a dispute from its screen, not a message").
 *
 * Both sides' claims are shown side by side with the reason the dispute was
 * raised, because deciding between two score lines without seeing them is
 * guessing. `recorded` says whether a result already stands: resolving then
 * runs as a correction and asks `CorrectResults` rather than `RecordResults`
 * (§8.4).
 */
#[TypeScript]
final class DeskDisputeData extends Data
{
    /**
     * @param  array<int, DeskReportData>  $reports
     */
    public function __construct(
        public readonly string $matchId,
        public readonly ?string $bracket,
        public readonly int $round,
        public readonly int $position,
        public readonly string $disputedAt,
        public readonly ?string $reason,
        public readonly bool $recorded,

        /** @var array<int, DeskReportData> */
        #[DataCollectionOf(DeskReportData::class)]
        public readonly array $reports,
    ) {}
}
