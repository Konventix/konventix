<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Konventix\Community\Enums\ConnectionStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the room's presence list (design §5): the two composing axes
 * side by side. `status` is the ephemeral connection axis from Redis;
 * `checkedIn` is the durable admission badge from `community_event_presence`
 * — displayed, never enforced (ADR-0067 decision 2).
 *
 * "Checked-In + Offline" is a real row: at the event, phone in a pocket.
 * It renders as a badge with no dot.
 */
#[TypeScript]
final class ShoutboxPresenceData extends Data
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $displayName,
        public readonly ConnectionStatus $status,
        public readonly bool $checkedIn,
    ) {}
}
