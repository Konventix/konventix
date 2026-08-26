<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the pages index (ADR-0009): the fields the list shows, not the
 * full composition {@see StaticPageData} carries for the editor.
 */
#[TypeScript]
final class StaticPageRowData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $brandName,
        public readonly bool $published,
        public readonly ?string $updatedAt,
    ) {}
}
