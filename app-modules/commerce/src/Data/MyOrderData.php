<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Illuminate\Support\Facades\URL;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One order as its payer sees it in "my orders" (ADR-0009, ADR-0044): what it
 * settles, what it costs, whether it is paid, and the invoice if there is one.
 *
 * `documentUrl` is a temporary signed link minted here, inside the render
 * that already established the reader is the payer — the same shape the
 * per-registration slot uses, for the same reason.
 *
 * `lines` is the per-line breakdown (Task 11): a cart order settles a mix
 * of products and registrations, so the order-level summary alone no
 * longer says enough — `$order->lines` must already be eager-loaded, the
 * way `MyOrdersController` loads it for the whole list.
 *
 * `invoicePaid` (ADR-0048) is whether the invoice — if there is one —
 * carries a payment snapshot, so the page can label its document button
 * "Rechnung (bezahlt)" rather than "Rechnung".
 *
 * `creditNotes` (ADR-0048 point 2, Task 3): every Gutschrift issued against
 * this order, newest last — a refund of a paid order leaves one behind, and
 * the payer must be able to find it beside the invoice it corrects. Default
 * empty array so every existing `ofOrder()` call site stays valid
 * (additive-only payload changes, global constraints D-BATCH-7).
 */
#[TypeScript]
final class MyOrderData extends Data
{
    /**
     * @param  list<MyOrderLineData>  $lines
     * @param  list<MyCreditNoteData>  $creditNotes
     */
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
        public readonly bool $invoicePaid,
        public readonly ?string $payUrl,
        public readonly ?string $documentUrl,
        public readonly array $lines,
        public readonly array $creditNotes = [],
    ) {}

    /**
     * `of*`, never `from*` (.ai/rules/data.md).
     *
     * @param  list<CreditNote>  $creditNotes
     */
    public static function ofOrder(Order $order, ?Invoice $invoice, array $creditNotes = []): self
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
            invoicePaid: $invoice?->paid_at !== null,
            payUrl: $order->status === OrderStatus::Pending && $order->settles_type === 'registration'
                ? route('commerce.pay.show', ['registration' => $order->settles_id], absolute: false)
                : null,
            documentUrl: $invoice?->document_path === null ? null : URL::temporarySignedRoute(
                'commerce.my.invoice.document',
                now()->addMinutes(30),
                ['invoice' => $invoice->getKey()],
                absolute: false,
            ),
            lines: array_values($order->lines->map(fn (OrderLine $line): MyOrderLineData => MyOrderLineData::ofLine($line))->all()),
            creditNotes: array_map(
                fn (CreditNote $creditNote): MyCreditNoteData => MyCreditNoteData::ofCreditNote($creditNote),
                $creditNotes,
            ),
        );
    }
}
