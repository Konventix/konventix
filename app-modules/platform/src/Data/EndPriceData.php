<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Pricing\EndPriceCalculator;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One computed end price, as {@see EndPriceCalculator}
 * answers it (#234, D-COM-20).
 *
 * Both amounts travel, not just the gross one: ADR-0006 hangs the
 * net-versus-gross *display* on the offer itself — a B2B audience is
 * honestly shown a net amount with a label — so the surface picks with
 * `displayUnitAmount()` and neither the browser nor the page does
 * arithmetic on money (#3 PAngV).
 *
 * All amounts are decimal strings; money never travels as a float.
 * `taxRate` is the rate the calculation actually resolved ("19.00",
 * "7.00", or "0.00" where none applies), not the one the offer declared —
 * ADR-0017's "the declared rate is an intention, not a guarantee".
 *
 * `includesTax` is false under the operator's §19 UStG election
 * (ADR-0030), where the amount contains no VAT at all: "incl. VAT" would
 * claim a tax the invoice does not carry, and §19 (1) sentence 4 UStG
 * wants the absence stated. `taxNote()` in `resources/js/lib/purchaseTexts.ts`
 * is the one place that wording lives.
 *
 * `currency` is the brand's (ADR-0001 — fixed per brand), so a public page
 * never hardcodes a symbol.
 */
#[TypeScript]
final class EndPriceData extends Data
{
    public function __construct(
        public readonly string $unitAmountNet,
        public readonly string $unitAmountGross,
        public readonly string $taxRate,
        public readonly string $currency,
        public readonly bool $includesTax,
    ) {}
}
