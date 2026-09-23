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
 * Only people active in the room have a row (`WallReader::presence()`,
 * ADR-0064), so `status` is `online` or `away` in practice. The row carries
 * no account ULID: the list is display only.
 */
#[TypeScript]
final class ShoutboxPresenceData extends Data
{
    public function __construct(
        public readonly ?string $displayName,
        public readonly ConnectionStatus $status,
        public readonly bool $checkedIn,
    ) {}
}
