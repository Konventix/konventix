<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The brand edit form (ADR-0009). Separate from `BrandData` (the create
 * dialog) because editing also exposes `currency`, which creation leaves at
 * the database default.
 */
#[TypeScript]
class UpdateBrandData extends Data
{
    /**
     * @param  array<int, string>  $hosts
     */
    public function __construct(
        public string $name,
        #[Regex('/^[a-z0-9-]+$/')]
        public string $slug,
        #[Regex('/^[A-Z]{3}$/')]
        public string $currency,
        public array $hosts,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3'],
            'hosts' => ['required', 'array', 'min:1'],
            'hosts.*' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/'],
        ];
    }
}
