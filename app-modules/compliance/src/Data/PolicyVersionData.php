<?php

declare(strict_types=1);

namespace Konventix\Compliance\Data;

use Carbon\CarbonImmutable;
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
 * `effectiveAt` is nullable here because a draft may not have a date yet —
 * `PublishPolicyVersion` refuses to publish one that still has none.
 *
 * It travels as an ISO-8601 string *with its offset*, both ways. The console's
 * `datetime-local` control speaks bare wall clock, and reading that in
 * `config('app.timezone')` (UTC) dated every version an operator meant as
 * "from now on" one offset into the future — `Policy::currentVersion()`
 * filters on `effective_at <= now()`, so the text 404'd until it arrived.
 * `effectiveAtInstant()` is the one place the string becomes a moment.
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
            effectiveAt: $version->effective_at?->toIso8601String(),
        );
    }

    /**
     * The submitted date as an absolute moment in UTC.
     *
     * `policy_versions.effective_at` is a zone-less column and Eloquent
     * formats a Carbon in whatever zone that instance carries, so a value
     * parsed as `+02:00` would be written down as its local digits and read
     * back two hours wrong. Normalising here keeps that out of every caller.
     */
    public function effectiveAtInstant(): ?CarbonImmutable
    {
        if ($this->effectiveAt === null || $this->effectiveAt === '') {
            return null;
        }

        return CarbonImmutable::parse($this->effectiveAt)->utc();
    }
}
