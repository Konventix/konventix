<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The typed payload for `commerce.order-confirmation` (ADR-0009, ADR-0048
 * point 3) — a cart checkout's own order confirmation, sent the moment
 * `OrderPlaced` fires for `settlesType: 'cart'`. A direct (`registration`)
 * order's payer already hears their submission went through from
 * Registration, and — where invoice-settled — from Commerce's own Rechnung
 * mail moments later; this template exists for the cart checkout case only.
 *
 * `lines` stays a plain array shape rather than a nested Data class: this
 * task's file list names no `OrderConfirmationLineData`, and laravel-data's
 * `toArray()` passes a plain PHP array straight through unchanged, so the
 * template's `@foreach` sees exactly the `{label, quantity, lineGross}`
 * shape below with nothing to verify about a transformer round-trip.
 *
 * `reservationUntil` is null once the order settles directly, or the
 * checked-out cart behind it has already been consumed; when set it is the
 * still-open window the payer's hosted checkout has to finish within
 * (ADR-0047's `Cart::CHECKOUT_GRACE_MINUTES`).
 *
 * `payUrl` (GitLab #5) is where the order is paid while it still owes
 * money — `Settlement\PayLink` decides, so the confirmation and the
 * payment-failed mail cannot drift apart. Null on a settled order, and
 * defaulted so every existing `new OrderConfirmationMailData(...)` call
 * site stays valid (additive-only payload changes, global constraints
 * D-BATCH-7).
 */
#[TypeScript]
final class OrderConfirmationMailData extends Data
{
    /**
     * @param  list<array{label: string, quantity: int, lineGross: string}>  $lines
     */
    public function __construct(
        public readonly string $orderNumberShort,
        public readonly ?string $recipientName,
        public readonly string $placedAt,
        public readonly array $lines,
        public readonly string $totalGross,
        public readonly string $currency,
        public readonly bool $paid,
        public readonly ?string $reservationUntil,
        public readonly string $ordersUrl,
        public readonly ?string $payUrl = null,
    ) {}
}
