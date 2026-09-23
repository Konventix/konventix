<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;

/**
 * The seed order the desk dragged (design §7.5, D-CMP-20): entrant ULIDs,
 * index 0 is seed 1. `ManualSeedSource` refuses an id that is not in the
 * field and appends anybody left out, so a partial drag is a legal one.
 */
final class SeedOrderData extends Data
{
    /**
     * @param  array<int, string>  $entrantIds
     */
    public function __construct(
        public readonly array $entrantIds,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'entrantIds' => ['required', 'array', 'min:1'],
            'entrantIds.*' => ['required', 'ulid', 'distinct'],
        ];
    }
}
