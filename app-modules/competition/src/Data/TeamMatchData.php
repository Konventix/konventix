<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of a team's matches on its page, with the competition it belongs to —
 * a team is per event and may play several (D-CMP-14).
 */
#[TypeScript]
final class TeamMatchData extends Data
{
    public function __construct(
        public readonly string $competitionSlug,
        public readonly string $competitionName,
        public readonly BracketMatchData $match,
    ) {}
}
