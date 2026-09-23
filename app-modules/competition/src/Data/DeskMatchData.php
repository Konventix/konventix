<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\MatchState;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One match on the desk's bracket (design §9).
 *
 * `bracket` and `round` together are the round the per-round "schedule all
 * at" acts on — double elimination numbers its winners' and losers' rounds
 * independently (D-CMP-21).
 */
#[TypeScript]
final class DeskMatchData extends Data
{
    /**
     * @param  array<int, DeskSideData>  $sides
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $bracket,
        public readonly int $round,
        public readonly int $position,
        public readonly MatchState $state,
        public readonly ?string $scheduledAt,
        public readonly bool $disputed,

        /** @var array<int, DeskSideData> in slot order */
        #[DataCollectionOf(DeskSideData::class)]
        public readonly array $sides,
    ) {}
}
