<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The template editor's edit screen (Task 8, ADR-0048 decision 4): one
 * template key, every locale it defines, the module-registered default text
 * and the console brand's override side by side, and the sample payload the
 * preview and a saved override's compile check both render against.
 */
#[TypeScript]
final class TemplateEditData extends Data
{
    /**
     * @param  list<string>  $locales
     * @param  array<string, array{subject: string, body: string}>  $defaults
     * @param  array<string, array{subject: string, body: string}|null>  $overrides
     * @param  array<string, mixed>  $sample
     */
    public function __construct(
        public readonly string $key,
        public readonly string $module,
        public readonly array $locales,
        public readonly string $defaultLocale,
        public readonly array $defaults,
        public readonly array $overrides,
        public readonly array $sample,
        public readonly string $brandId,
        public readonly string $brandName,
    ) {}
}
