<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Models\SettlementAccount;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The operator's own words for one settlement account — the checkout button
 * (ADR-0046) and, for Stripe, what the payer reads on their card statement
 * (ADR-0058 point 3). Both are cosmetic and both are correctable in place:
 * `connectStripe()` refuses a second live Stripe row per brand, so a typo in
 * the descriptor would otherwise cost a disconnect and a re-enable, which is
 * a brand settling manually in between.
 *
 * `SettlementAccount::checkoutLabel()` falls back to the adapter's default
 * whenever the label is blank, and Stripe falls back to the account's own
 * descriptor whenever the suffix is, so an empty value is a legitimate "use
 * the default" choice in both cases, not something to forbid.
 */
#[TypeScript]
final class UpdateSettlementAccountLabelData extends Data
{
    public function __construct(
        #[Nullable, StringType, Max(80)]
        public readonly ?string $checkoutLabel,

        /**
         * The same budget and the same character rules the enable form
         * applies — both read the constants on the model so they cannot
         * drift apart.
         */
        #[Nullable, StringType, Max(SettlementAccount::STATEMENT_DESCRIPTOR_SUFFIX_MAX), Regex(SettlementAccount::STATEMENT_DESCRIPTOR_SUFFIX_PATTERN)]
        public readonly ?string $statementDescriptorSuffix = null,
    ) {}
}
