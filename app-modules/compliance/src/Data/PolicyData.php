<?php

declare(strict_types=1);

namespace Konventix\Compliance\Data;

use Konventix\Compliance\Models\Policy;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Single source of validation rules, Inertia props and generated TypeScript
 * for the policy itself (ADR-0009) — the stable slug and name its versions
 * publish under.
 *
 * The document text is deliberately absent: it belongs to a version, not to
 * the policy, and that separation is what makes "what did this person agree
 * to" answerable years later.
 */
#[TypeScript]
final class PolicyData extends Data
{
    public function __construct(
        #[Required, StringType, Max(255)]
        public readonly string $name,

        #[Required, StringType, Max(255), Regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')]
        public readonly string $slug,
    ) {}

    public static function fromModel(Policy $policy): self
    {
        return new self(
            name: $policy->name,
            slug: $policy->slug,
        );
    }
}
