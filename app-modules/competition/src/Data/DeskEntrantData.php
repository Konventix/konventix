<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\EntrantState;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entrant of the field, as the seed list shows it (design §7.5).
 */
#[TypeScript]
final class DeskEntrantData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $teamName,
        public readonly ?int $seed,
        public readonly EntrantState $state,
    ) {}
}
