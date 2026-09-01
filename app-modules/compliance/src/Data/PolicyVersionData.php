<?php

declare(strict_types=1);

namespace Konventix\Compliance\Data;

use Konventix\Compliance\Models\PolicyVersion;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The editable half of one policy version (ADR-0009): its label, its
 * markdown text, whether agreeing is required, and the date it takes effect.
 *
 * Carries no `state` and no `publishedAt` — publication is
 * `PublishPolicyVersion`, an Action with an event attached, not a field an
 * editor types over. The same split Content's entry editor keeps.
 *
 * `effectiveAt` is nullable here because a draft may not have a date yet;
 * `PublishPolicyVersion` falls back to now() rather than letting a version
 * publish without one.
 */
#[TypeScript]
final class PolicyVersionData extends Data
{
    public function __construct(
        #[Required, StringType, Max(50)]
        public readonly string $version,

        #[Required, StringType, Max(100000)]
        public readonly string $content,

        #[Required, BooleanType]
        public readonly bool $requiresConsent,

        #[Required, StringType, In(['consent', 'contract', 'legitimate_interest'])]
        public readonly string $lawfulBasis,

        #[Nullable, Date]
        public readonly ?string $effectiveAt = null,
    ) {}

    public static function fromModel(PolicyVersion $version): self
    {
        return new self(
            version: $version->version,
            content: $version->content,
            requiresConsent: $version->requires_consent,
            lawfulBasis: $version->lawful_basis->value,
            effectiveAt: $version->effective_at?->format('Y-m-d H:i:s'),
        );
    }
}
