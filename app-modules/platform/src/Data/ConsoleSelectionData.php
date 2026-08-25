<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the operator picked in the console shell (ROADMAP slice 18): a brand,
 * an event, or neither — "all brands" is a legitimate view of an operator
 * console, so both sides are nullable.
 *
 * The event is only checked for shape here. Whether it is one of the selected
 * brand's is a question only the module that owns events can answer, so the
 * controller asks `ConsoleEventSources` (ADR-0004).
 */
#[TypeScript]
final class ConsoleSelectionData extends Data
{
    public function __construct(
        #[Nullable, Ulid, Exists('brands', 'id')]
        public readonly ?string $brandId,
        #[Nullable, Ulid]
        public readonly ?string $eventId,
    ) {}
}
