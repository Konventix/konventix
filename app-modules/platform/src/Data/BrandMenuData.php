<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One brand's whole menu, submitted at once (ADR-0009) — the footer's
 * replace-all shape: the list is short, its order and nesting are what the
 * operator is editing, and one submit means the menu never exists half
 * saved on a live site.
 */
#[TypeScript]
class BrandMenuData extends Data
{
    /**
     * @param  array<int, BrandMenuItemData>  $items
     */
    public function __construct(
        #[Required, Ulid]
        public string $brandId,
        #[DataCollectionOf(BrandMenuItemData::class)]
        /** @var array<int, BrandMenuItemData> */
        public array $items = [],
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'brandId' => ['required', 'ulid', 'exists:brands,id'],
            'items' => ['present', 'array', 'max:30'],
        ];
    }
}
