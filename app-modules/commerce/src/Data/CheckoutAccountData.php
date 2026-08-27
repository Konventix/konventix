<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\SettlementAdapter;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One settlement account offered to the payer on the checkout page
 * (ADR-0046): which provider it is, and the label its button shows — the
 * operator's own words, or the adapter's default (`checkoutLabel()`).
 */
#[TypeScript]
final class CheckoutAccountData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly string $label,
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md).
     */
    public static function ofAccount(SettlementAccount $account, SettlementAdapter $adapter): self
    {
        return new self(
            id: $account->id,
            provider: $account->provider,
            label: $account->checkoutLabel($adapter),
        );
    }
}
