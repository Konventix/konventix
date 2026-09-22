<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The room's state as the desk sees it (design §8): the lock switch and
 * whether `EventConcluded` has closed the wall for good. Instants are
 * ISO-8601 strings.
 */
#[TypeScript]
final class ModerationRoomData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $lockedAt,
        public readonly ?string $lockedReason,
        public readonly ?string $closedAt,
    ) {}
}
