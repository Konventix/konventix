<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The picker's submission (design §7.4): the element ids the registrant
 * chose. Count against the claim's quantity, eligibility and availability
 * are `AssignPlacesToRegistration`'s job, inside its transaction; this only
 * guarantees a non-empty list of distinct ULIDs.
 */
#[TypeScript]
final class PickPlacesData extends Data
{
    /**
     * @param  list<string>  $placeIds
     */
    public function __construct(
        /** @var list<string> */
        public readonly array $placeIds,
    ) {}

    /**
     * Written in full — laravel-data replaces the inferred rules for the
     * keys returned here.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'placeIds' => ['required', 'array', 'min:1'],
            'placeIds.*' => ['ulid', 'distinct'],
        ];
    }
}
