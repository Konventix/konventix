<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The brand create/update form (ADR-0009): one definition for validation,
 * Inertia props and TypeScript.
 */
#[TypeScript]
class BrandData extends Data
{
    /**
     * @param  array<int, string>  $hosts
     */
    public function __construct(
        public string $name,
        #[Regex('/^[a-z0-9-]+$/')]
        public string $slug,
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
            'hosts' => ['required', 'array', 'min:1'],
            'hosts.*' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/'],
        ];
    }
}
