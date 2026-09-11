<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Enums\PlanWarningCode;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A non-blocking finding of `PlanWarnings::collect()` (design §6.1 step 5):
 * two places overlap within a zone, or a place lies outside every zone. The
 * editor shows them; the save goes through regardless. `$code` is the
 * `PlanWarningCode` enum from Task 10, so the generated TypeScript gives the
 * validation panel (S7, Task 56) a closed set to switch on; `$placeIds` is
 * always a list, never a single id.
 */
#[TypeScript]
final class PlanWarningData extends Data
{
    /**
     * @param  list<string>  $placeIds
     */
    public function __construct(
        public readonly PlanWarningCode $code,

        /** @var list<string> */
        public readonly array $placeIds,

        public readonly string $message,
    ) {}
}
