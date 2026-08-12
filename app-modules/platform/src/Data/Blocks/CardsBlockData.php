<?php

declare(strict_types=1);

namespace Konventix\Platform\Data\Blocks;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Payload of the built-in `cards` block: a key-details-style grid, e.g. "Date
 * & Time" / "Venue" / "Registration" cards on a brand's home page.
 */
#[TypeScript]
class CardsBlockData extends Data
{
    /**
     * @param  array<int, CardItemData>  $cards
     */
    public function __construct(
        #[DataCollectionOf(CardItemData::class)]
        public array $cards,
    ) {}
}
