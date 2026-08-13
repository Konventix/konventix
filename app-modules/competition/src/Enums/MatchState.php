<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * A match's lifecycle.
 *
 * `Finished` is terminal and reached exactly once (D-CMP-12): a correction
 * rewrites the recorded result without moving the state, which is what lets
 * `MatchFinished` fire once per match and keeps GameOps from tearing the same
 * server down twice.
 */
enum MatchState: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Finished = 'finished';
    case Cancelled = 'cancelled';
}
