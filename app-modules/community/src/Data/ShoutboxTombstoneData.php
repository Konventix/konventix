<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A removed line as participants see it: "Von der Orga entfernt" (spec
 * §4.2) — the id, the room and the instant, and **nothing else**.
 *
 * This is the one shape both halves of the realtime path send, and that is
 * its whole reason to exist. `ShoutboxMessageRemoved::broadcastWith()`
 * builds its wire payload from this class, and so does every HTTP read that
 * replays a removal — the room page, the hub card and the reconciliation
 * fetch a client makes after its socket dropped. A catch-up path that
 * returned `reason` or `removedByUserId` would leak exactly what the
 * broadcast withholds; with one class behind both, it cannot.
 */
#[TypeScript]
final class ShoutboxTombstoneData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $roomId,
        public readonly string $removedAt,
    ) {}
}
