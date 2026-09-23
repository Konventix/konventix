<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The stage the desk draws and runs (design §7.5, §9).
 *
 * `hasResult` is the line D-CMP-20 draws — until the first recorded result
 * the desk may re-seed, regenerate and reset; after it, none of the three.
 * The heat count and unit are the leaderboard's (§7.2), for the desk's own
 * score form.
 */
#[TypeScript]
final class DeskStageData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $format,
        public readonly bool $isLeaderboard,
        public readonly bool $generated,
        public readonly bool $hasResult,
        public readonly ?int $heats,
        public readonly ?string $unit,
    ) {}
}
