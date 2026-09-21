<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Models\OrderLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of an order as the payer sees it — "my orders", the order's own
 * page, and both pay pages (ADR-0047, Task 11; bugfix round 2026-09-21,
 * Task 17b) — needed once a cart checkout can produce one order settling a
 * mix of products and registrations, where the order-level summary alone no
 * longer says enough.
 *
 * `registrationUrl` resolves `registration.mine.show` here, server-side,
 * the same way `MyOrderData` already resolves `payUrl`/`documentUrl`: only
 * a `registration`-kind line has one, since only Registration owns a page
 * to send the payer to for it. `productUrl` is the shop product page of a
 * `product`-kind line, resolved by `Cart\ProductPageUrls` (published
 * product, shop on) and handed in by the caller — null when that page would
 * 404. A line never carries both; the page links whichever is set.
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
        public readonly ?string $productUrl,
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md).
     *
     * @param  array<string, string>  $productUrls  variant id → product page URL (`Cart\ProductPageUrls`)
     */
    public static function ofLine(OrderLine $line, array $productUrls = []): self
    {
        return new self(
            label: $line->label,
            quantity: $line->quantity,
            lineGross: $line->line_gross,
            kind: $line->settles_kind,
            registrationUrl: $line->settles_kind === 'registration' && $line->settles_ref !== null
                ? route('registration.mine.show', ['registration' => $line->settles_ref], absolute: false)
                : null,
            productUrl: $line->settles_kind === 'product' && $line->settles_ref !== null
                ? ($productUrls[$line->settles_ref] ?? null)
                : null,
        );
    }
}
