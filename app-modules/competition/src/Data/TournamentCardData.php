<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\CompetitionStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of an event's tournament list (design §10): "discipline, format,
 * team size, status, entrants/limit, starts at". `format` is the first
 * stage's, null for a competition that has none yet.
 */
#[TypeScript]
final class TournamentCardData extends Data
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $disciplineName,
        public readonly ?string $format,
        public readonly int $teamSize,
        public readonly CompetitionStatus $status,
        public readonly int $entrants,
        public readonly ?int $maxEntrants,
        public readonly ?string $startsAt,
    ) {}
}
