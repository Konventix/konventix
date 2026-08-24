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
        /** Media ULIDs (ADR-0035); null means "fall back to the bundled mark". */
        public ?string $logoMediaId = null,
        public ?string $logoDarkMediaId = null,
        public ?string $faviconMediaId = null,
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
            // Existence only; that the item belongs to THIS brand is a
            // cross-record invariant and is guarded in the action, where the
            // brand is known.
            'logoMediaId' => ['nullable', 'ulid', 'exists:media_items,id'],
            'logoDarkMediaId' => ['nullable', 'ulid', 'exists:media_items,id'],
            'faviconMediaId' => ['nullable', 'ulid', 'exists:media_items,id'],
        ];
    }
}
