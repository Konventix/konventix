<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\DisciplineKind;
use Konventix\Competition\Models\Discipline;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The discipline form of the console (design §9): one source of validation,
 * props and TypeScript (ADR-0009).
 *
 * `Discipline` is the source of record for contest defaults (ADR-0062,
 * D-GOP-04 amended) — team size, duration hint and draws live here, for
 * every kind, so no consumer needs a null-game fallback chain. `gameId` is
 * the module's single GameOps reference: a plain ULID with no FK
 * (ADR-0014), null for every kind that is not a video game. There is no
 * catalogue picker yet because GameOps publishes no catalogue event; the
 * field takes the id by hand until it does.
 *
 * Uniqueness of the slug per brand is the table's index and the Action's
 * job, not a rule here.
 */
#[TypeScript]
final class DisciplineData extends Data
{
    public function __construct(
        #[Required]
        public readonly DisciplineKind $kind,

        #[Required, StringType, Max(255)]
        public readonly string $name,

        #[Required, StringType, Max(255), Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
        public readonly string $slug,

        #[Required, IntegerType, Min(1), Max(64)]
        public readonly int $teamSize,

        #[Required, IntegerType, Min(1), Max(1440)]
        public readonly int $avgMatchMinutes,

        #[Required, BooleanType]
        public readonly bool $allowDraws,

        #[Nullable, Ulid]
        public readonly ?string $gameId = null,
    ) {}

    public static function fromModel(Discipline $discipline): self
    {
        return new self(
            kind: $discipline->kind,
            name: $discipline->name,
            slug: $discipline->slug,
            teamSize: $discipline->team_size,
            avgMatchMinutes: $discipline->avg_match_minutes,
            allowDraws: $discipline->allow_draws,
            gameId: $discipline->game_id,
        );
    }
}
