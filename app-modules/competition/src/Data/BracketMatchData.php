<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\MatchState;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One match of the public bracket (design §10): where it sits (`bracket`,
 * `round`, `position` — the generator's coordinates), when it is played,
 * and its sides in slot order.
 */
#[TypeScript]
final class BracketMatchData extends Data
{
    /**
     * @param  array<int, BracketSideData>  $sides
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $bracket,
        public readonly int $round,
        public readonly int $position,
        public readonly MatchState $state,
        public readonly ?string $scheduledAt,
        public readonly bool $disputed,

        /** @var array<int, BracketSideData> in slot order */
        #[DataCollectionOf(BracketSideData::class)]
        public readonly array $sides,
    ) {}
}
