<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the plan console's list (design §8.2: name, event, places,
 * version, published). `publishedAt` is an ISO-8601 instant or null; the
 * page renders it through `formatDateTime()` (.ai/rules/js.md).
 */
#[TypeScript]
final class PlanRowData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $eventName,
        public readonly ?string $floorLabel,
        public readonly int $places,
        public readonly int $version,
        public readonly ?string $publishedAt,
    ) {}
}
