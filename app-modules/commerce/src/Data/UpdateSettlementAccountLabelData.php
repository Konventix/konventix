<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The operator's own words for one settlement account's checkout button
 * (ADR-0046): `SettlementAccount::checkoutLabel()` falls back to the
 * adapter's default whenever this is blank, so an empty value is a
 * legitimate "use the default" choice, not something to forbid.
 */
#[TypeScript]
final class UpdateSettlementAccountLabelData extends Data
{
    public function __construct(
        #[Nullable, StringType, Max(80)]
        public readonly ?string $checkoutLabel,
    ) {}
}
