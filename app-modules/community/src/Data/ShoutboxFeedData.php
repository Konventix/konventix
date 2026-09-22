<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One page of the wall fetched by id: the lines after a cursor (the
 * reconciliation a client runs when its socket comes back) or before one
 * (scrolling upward). Always oldest first. `hasMore` says there is another
 * page in the same direction.
 */
#[TypeScript]
final class ShoutboxFeedData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function __construct(
        #[LiteralTypeScriptType('Array<Konventix.Community.Data.ShoutboxLineData | Konventix.Community.Data.ShoutboxTombstoneData>')]
        public readonly array $lines,
        public readonly bool $hasMore,
    ) {}
}
