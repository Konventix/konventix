<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Konventix\Messaging\Templates\MessageTemplate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the template editor's index (Task 8, ADR-0048 decision 4):
 * every key the registry knows about, and which of its locales the console
 * brand has overridden — not the rendered text itself, {@see TemplateEditData}
 * carries that.
 */
#[TypeScript]
final class TemplateSummaryData extends Data
{
    /**
     * @param  list<string>  $locales
     * @param  list<string>  $overriddenLocales
     */
    public function __construct(
        public readonly string $key,
        public readonly string $module,
        public readonly array $locales,
        public readonly string $defaultLocale,
        public readonly array $overriddenLocales,
    ) {}

    /**
     * @param  list<string>  $overriddenLocales
     */
    public static function ofTemplate(MessageTemplate $template, array $overriddenLocales): self
    {
        $module = strstr($template->key, '.', true);

        return new self(
            key: $template->key,
            module: $module === false ? $template->key : $module,
            locales: array_keys($template->subjects),
            defaultLocale: $template->defaultLocale,
            overriddenLocales: $overriddenLocales,
        );
    }
}
