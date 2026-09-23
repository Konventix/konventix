<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;

/**
 * A captain enters one of their teams (design §5.2, §10). Only the team is
 * chosen: the roster entering is whoever is on it (`EnterCompetition` with no
 * member list).
 */
final class EnterCompetitionData extends Data
{
    public function __construct(
        #[Required, Ulid]
        public readonly string $teamId,
    ) {}
}
