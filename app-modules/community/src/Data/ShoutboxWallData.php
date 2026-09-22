<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The wall as one participant sees it right now: the latest lines oldest
 * first, and what stands between them and the compose box. Shared by the
 * hub card and the room page, which differ only in how many lines they
 * show.
 *
 * `notice` is the room's own state (locked, closed) and `mute` the viewer's
 * — both are the German sentences `ShoutboxWriteException` writes, so the
 * compose box says exactly what a refused post would say, and there is one
 * copy of each. `retryAfterSeconds` is the timed mute's remaining time for
 * the countdown; null for an indefinite mute and for no mute at all.
 *
 * `lines` holds lines and tombstones in one list, ordered by id — the
 * transformer cannot name a union element type, so it is spelled out.
 */
#[TypeScript]
final class ShoutboxWallData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function __construct(
        public readonly string $roomId,
        #[LiteralTypeScriptType('Array<Konventix.Community.Data.ShoutboxLineData | Konventix.Community.Data.ShoutboxTombstoneData>')]
        public readonly array $lines,
        public readonly bool $hasOlder,
        public readonly ?string $notice,
        public readonly ?string $mute,
        public readonly ?int $retryAfterSeconds,
        public readonly int $maxLength,
    ) {}
}
