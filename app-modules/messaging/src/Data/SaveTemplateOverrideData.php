<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The template editor's save form (Task 8, ADR-0048 decision 4): a brand's
 * rewrite of one template key, in one locale. Carries no `brandId` — the
 * brand a save writes into is always `ConsoleSelection`'s (session,
 * server-side), never a value the client's own request body names; letting
 * the client name it would let any holder of `manage-message-templates` (a
 * brand-operator capability) write into a brand nobody handed them the
 * console selection for. Whether `locale` is actually one of the template's
 * defined locales is checked in the controller — it depends on the `{key}`
 * route parameter, which a Data class's own rules cannot see.
 */
#[TypeScript]
final class SaveTemplateOverrideData extends Data
{
    public function __construct(
        #[Required, StringType]
        public readonly string $locale,
        #[Required, StringType, Max(200)]
        public readonly string $subject,
        #[Required, StringType, Max(20000)]
        public readonly string $body,
    ) {}
}
