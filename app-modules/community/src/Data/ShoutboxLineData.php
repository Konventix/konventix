<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One shoutbox line as a participant's browser receives it (design §8) —
 * over the private room channel (`ShoutboxMessagePosted::broadcastWith()`)
 * and over HTTP (the room page, the hub card, the reconciliation fetch).
 * One class so that the two paths cannot drift into two shapes.
 *
 * `body` is nullable only for the broadcast path, where the row is read back
 * after the commit and could in principle be gone; every HTTP read hands
 * over the row it just loaded. `authorDisplayName` is null for an account
 * Community has not heard of yet.
 *
 * A **removed** line never takes this shape: it is a `ShoutboxTombstoneData`,
 * which carries neither body nor author (spec §4.2).
 */
#[TypeScript]
final class ShoutboxLineData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $roomId,
        public readonly string $authorUserId,
        public readonly ?string $authorDisplayName,
        public readonly ?string $body,
        public readonly string $postedAt,
    ) {}
}
