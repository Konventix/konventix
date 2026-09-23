<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A team the signed-in captain could enter with (design §5.2, §10): theirs,
 * at this event, and not yet in this competition.
 */
#[TypeScript]
final class TeamOptionData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $members,
    ) {}
}
