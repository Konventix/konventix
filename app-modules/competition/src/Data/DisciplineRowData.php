<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\DisciplineKind;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the discipline console's list (ADR-0009: the page's row type is
 * generated from this, never hand-written).
 *
 * `competitions` is how many contests already run this discipline — the
 * number that says whether renaming it is a cosmetic edit or a rewrite of
 * what people entered.
 */
#[TypeScript]
final class DisciplineRowData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly DisciplineKind $kind,
        public readonly string $brandName,
        public readonly int $teamSize,
        public readonly int $avgMatchMinutes,
        public readonly bool $allowDraws,
        public readonly ?string $gameId,
        public readonly int $competitions,
    ) {}
}
