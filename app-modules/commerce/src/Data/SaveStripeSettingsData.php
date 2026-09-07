<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StartsWith;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The Stripe account this instance settles into, as an operator types it
 * into the console (ADR-0058 point 2, #215).
 *
 * The prefixes are validated because Stripe's own key shapes are the only
 * early warning there is: a publishable key pasted into the secret field,
 * or an endpoint's id instead of its signing secret, would otherwise fail
 * at the first real payment rather than at the form. The publishable key is
 * optional and unused — hosted Checkout renders no card field on any
 * Konventix page (ADR-0039 point 1) — and is kept only for completeness.
 */
#[TypeScript]
final class SaveStripeSettingsData extends Data
{
    public function __construct(
        #[Required, StringType, Max(255), Regex('/^sk_(test|live)_.+$/')]
        public readonly string $secretKey,

        #[Required, StringType, Max(255), StartsWith('whsec_')]
        public readonly string $webhookSecret,

        #[Nullable, StringType, Max(255), Regex('/^pk_(test|live)_.+$/')]
        public readonly ?string $publishableKey,
    ) {}
}
