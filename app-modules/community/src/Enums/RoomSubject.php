<?php

declare(strict_types=1);

namespace Konventix\Community\Enums;

/**
 * What a `Room` is attached to (design §4.1, ADR-0067 "Consequences").
 * `Event` is the only subject 0.9.0 creates — a room is looked up or
 * created lazily on first read, never backfilled. `Match` and `Competition`
 * ship with the schema ready and wait on sub-project B: do not delete them,
 * and nothing in this release builds anything that creates them.
 */
enum RoomSubject: string
{
    case Event = 'event';
    case Match = 'match';
    case Competition = 'competition';
}
