<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The admin-favorites pin form (ADR-0009): one definition for validation,
 * Inertia props and TypeScript.
 */
#[TypeScript]
class AdminFavoriteData extends Data
{
    public function __construct(
        public string $label,
        public string $url,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048'],
        ];
    }
}
