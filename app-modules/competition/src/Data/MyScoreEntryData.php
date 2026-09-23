<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the viewer's leaderboard scores and where it stands (design §8.3,
 * §10): `pending` until the desk looks at it, then `verified` — it counts —
 * or `rejected` with the desk's reason.
 */
#[TypeScript]
final class MyScoreEntryData extends Data
{
    public function __construct(
        public readonly string $competitionName,
        public readonly string $teamName,
        public readonly ?int $heat,
        public readonly float $value,
        public readonly string $state,
        public readonly ?string $rejectionNote,
    ) {}
}
