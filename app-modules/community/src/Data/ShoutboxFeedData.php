<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One page of the wall fetched by id: the lines after a cursor (the
 * reconciliation a client runs when its socket comes back) or before one
 * (scrolling upward). Always oldest first. `hasMore` says there is another
 * page in the same direction.
 *
 * `removed` and `syncedAt` close the gap an id cursor cannot see: a line
 * the client already holds that was removed while its socket was down.
 * `syncedAt` is the server's instant for this answer; the next catch-up
 * hands it back as `since`, and `removed` then carries a tombstone for
 * every line of the room removed at or after it — in exactly the
 * broadcast's shape (`ShoutboxTombstoneData`), never the reason or the
 * organizer. The client applies them only to lines it holds.
 */
#[TypeScript]
final class ShoutboxFeedData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, ShoutboxTombstoneData>  $removed
     */
    public function __construct(
        #[LiteralTypeScriptType('Array<Konventix.Community.Data.ShoutboxLineData | Konventix.Community.Data.ShoutboxTombstoneData>')]
        public readonly array $lines,
        public readonly bool $hasMore,

        /** @var array<int, ShoutboxTombstoneData> */
        #[DataCollectionOf(ShoutboxTombstoneData::class)]
        public readonly array $removed,
        public readonly string $syncedAt,
    ) {}
}
