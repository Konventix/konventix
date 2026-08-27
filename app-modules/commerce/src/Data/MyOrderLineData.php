<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Models\OrderLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of an order in "my orders" (ADR-0047, Task 11) — needed once a
 * cart checkout can produce one order settling a mix of products and
 * registrations, where the order-level summary alone no longer says enough.
 *
 * `registrationUrl` resolves `registration.mine.show` here, server-side,
 * the same way `MyOrderData` already resolves `payUrl`/`documentUrl`: only
 * a `registration`-kind line has one, since only Registration owns a page
 * to send the payer to for it.
 */
#[TypeScript]
final class MyOrderLineData extends Data
{
    public function __construct(
        public readonly string $label,
        public readonly int $quantity,
        public readonly string $lineGross,
        public readonly ?string $kind,
        public readonly ?string $registrationUrl,
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md).
     */
    public static function ofLine(OrderLine $line): self
    {
        return new self(
            label: $line->label,
            quantity: $line->quantity,
            lineGross: $line->line_gross,
            kind: $line->settles_kind,
            registrationUrl: $line->settles_kind === 'registration' && $line->settles_ref !== null
                ? route('registration.mine.show', ['registration' => $line->settles_ref], absolute: false)
                : null,
        );
    }
}
