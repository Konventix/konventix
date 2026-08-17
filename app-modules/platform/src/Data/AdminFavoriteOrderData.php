<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The admin-favorites reorder payload (ADR-0042): the favorite ids in the
 * order the user dragged them into, top first.
 */
#[TypeScript]
class AdminFavoriteOrderData extends Data
{
    /**
     * @param  array<int, string>  $ids
     */
    public function __construct(
        /** @var array<int, string> */
        public array $ids,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'ulid'],
        ];
    }
}
