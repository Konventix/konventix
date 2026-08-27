<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Enums\PriceDisplay;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the products index (ADR-0009, Task 7): the fields the list
 * shows, not the full editable shape {@see ProductData} carries.
 */
#[TypeScript]
final class ProductRowData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $brandName,
        public readonly string $unitAmountNet,
        public readonly PriceDisplay $priceDisplay,
        public readonly bool $published,
        public readonly ?string $updatedAt,
    ) {}
}
