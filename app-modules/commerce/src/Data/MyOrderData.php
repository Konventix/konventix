<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Illuminate\Support\Facades\URL;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One order as its payer sees it in "my orders" (ADR-0009, ADR-0044): what it
 * settles, what it costs, whether it is paid, and the invoice if there is one.
 *
 * `documentUrl` is a temporary signed link minted here, inside the render
 * that already established the reader is the payer — the same shape the
 * per-registration slot uses, for the same reason.
 */
#[TypeScript]
final class MyOrderData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $placedAt,
        public readonly string $settlesType,
        public readonly string $settlesId,
        public readonly string $settlesLabel,
        public readonly OrderStatus $status,
        public readonly string $currency,
        public readonly string $totalGross,
        public readonly ?string $invoiceNumber,
        public readonly ?string $payUrl,
        public readonly ?string $documentUrl,
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md).
     */
    public static function ofOrder(Order $order, ?Invoice $invoice): self
    {
        $firstLine = $order->lines->first();

        // Not `$firstLine?->label ?? ...`: Larastan mistypes
        // `Collection::first()` here as never-null, which makes the nullsafe
        // form a phpstan false positive (`nullsafe.neverNull`) — an explicit
        // null check sidesteps it without changing the behaviour.
        $settlesLabel = $firstLine !== null
            ? $firstLine->label
            : str_replace('_', ' ', ucfirst($order->settles_type));

        return new self(
            id: $order->getKey(),
            placedAt: $order->created_at?->toIso8601String() ?? now()->toIso8601String(),
            settlesType: $order->settles_type,
            settlesId: $order->settles_id,
            settlesLabel: $settlesLabel,
            status: $order->status,
            currency: $order->currency,
            totalGross: $order->total_gross,
            invoiceNumber: $invoice?->invoice_number,
            payUrl: $order->status === OrderStatus::Pending && $order->settles_type === 'registration'
                ? route('commerce.pay.show', ['registration' => $order->settles_id], absolute: false)
                : null,
            documentUrl: $invoice?->document_path === null ? null : URL::temporarySignedRoute(
                'commerce.my.invoice.document',
                now()->addMinutes(30),
                ['invoice' => $invoice->getKey()],
                absolute: false,
            ),
        );
    }
}
