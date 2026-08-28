<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The template editor's live-preview request (Task 8, ADR-0048 decision 4):
 * a draft subject/body — not necessarily saved, not necessarily valid Blade
 * — rendered against the template's sample payload and wrapped in the
 * branded layout for the given brand and locale.
 */
#[TypeScript]
final class TemplatePreviewData extends Data
{
    public function __construct(
        #[Required, StringType, Max(200)]
        public readonly string $subject,
        #[Required, StringType, Max(20000)]
        public readonly string $body,
        #[Required, StringType]
        public readonly string $locale,
        #[Required, Ulid, Exists('brands', 'id')]
        public readonly string $brandId,
    ) {}
}
