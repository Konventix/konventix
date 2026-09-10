<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Enums\CapacityMode;
use Konventix\Resources\Enums\CategoryToken;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the category console's list (ADR-0009: the page's row type
 * is generated from this, never hand-written). `eventName` is this
 * module's own projection of Event's contract event (design §4.7).
 *
 * `places` is the **bookable** count — published, not blocked, not
 * deleted (`Resource::scopeBookable()`) — counted with the brand scope
 * escaped because the list runs on the admin host (ADR-0029). It is
 * deliberately not a raw row count: this is the number the operator reads
 * off this screen to size the category's Registration quota capacity
 * (design §12b, #282), and a raw count would overstate what is actually
 * sellable. The list's "Places" column is labelled "Bookable places" so
 * the number on screen says what it is.
 */
#[TypeScript]
final class ResourceTypeRowData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $eventName,
        public readonly CapacityMode $mode,
        public readonly CategoryToken $token,
        public readonly ?string $symbol,
        public readonly int $sortOrder,
        public readonly int $places,
    ) {}
}
