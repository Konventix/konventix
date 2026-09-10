<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Enums\CategoryToken;
use Konventix\Resources\Models\ResourceType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A category as the picker and the editor legend show it (design §7.4):
 * name, token and symbol, nothing bookable. `fromModel` is a laravel-data
 * magic creator over a typed model parameter, the `RegistrationTypeData`
 * precedent; this class is never an Eloquent cast.
 */
#[TypeScript]
final class CategoryData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly CategoryToken $token,
        public readonly ?string $symbol,
        public readonly ?string $description,
    ) {}

    public static function fromModel(ResourceType $type): self
    {
        return new self(
            id: $type->id,
            name: $type->name,
            token: $type->token,
            symbol: $type->symbol,
            description: $type->description,
        );
    }
}
