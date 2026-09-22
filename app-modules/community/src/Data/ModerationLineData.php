<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One recent line on the desk (design §8) — the removal verb needs a line
 * to act on even when nobody reported it. A removed line keeps its body and
 * carries the desk's own reason, both of which the room never sees.
 */
#[TypeScript]
final class ModerationLineData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $authorUserId,
        public readonly ?string $authorDisplayName,
        public readonly string $body,
        public readonly string $postedAt,
        public readonly ?string $removedAt,
        public readonly ?string $removedReason,
    ) {}
}
