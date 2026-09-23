<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The drawn stage, once, for both of its renderings (design §10, D-CMP-22):
 * "The canvas reads the same `BracketData` the table does." Matches are in
 * play order — double elimination's winners, losers and grand final, a group
 * stage's groups by name — then by round and position.
 */
#[TypeScript]
final class BracketData extends Data
{
    /**
     * @param  array<int, BracketMatchData>  $matches
     * @param  array<int, BracketConnectionData>  $connections
     */
    public function __construct(
        public readonly string $format,

        /** @var array<int, BracketMatchData> */
        #[DataCollectionOf(BracketMatchData::class)]
        public readonly array $matches,

        /** @var array<int, BracketConnectionData> */
        #[DataCollectionOf(BracketConnectionData::class)]
        public readonly array $connections,
    ) {}
}
