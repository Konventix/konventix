<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Models\CommerceBrandSettings;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A brand's Commerce settings form (ADR-0057 point 4,
 * `CommerceBrandSettingsAdminController::update()`), and the shape the
 * settlement-accounts console reads its current values from
 * (`CommerceBrandSettings::forBrand()`'s result, via `ofModel()`).
 *
 * One to sixty days. Below one there is no window at all — the sweep would
 * cancel the order before the payer could have been told — and above sixty
 * the receivable has outlived the event it paid for, which is a dunning
 * case rather than a repayment window.
 */
#[TypeScript]
final class CommerceBrandSettingsData extends Data
{
    public function __construct(
        #[Required, IntegerType, Min(1), Max(60)]
        public readonly int $reversalRepaymentDays,
    ) {}

    public static function ofModel(CommerceBrandSettings $settings): self
    {
        return new self(reversalRepaymentDays: $settings->reversal_repayment_days);
    }
}
