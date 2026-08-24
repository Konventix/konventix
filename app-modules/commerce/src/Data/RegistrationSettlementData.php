<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How one registration was settled, as the person who registered sees it
 * (ADR-0009): what it costs, whether it is paid, and the invoice if one was
 * issued.
 *
 * Commerce cannot find this by itself. An order is keyed by
 * `settles_type`/`settles_id`, so resolving "whose orders are these" would
 * mean reading Registration's tables, which ADR-0004 forbids — the same
 * boundary that leaves Commerce without a GDPR handler. What closes it here
 * is direction: Registration already knows the registration is the reader's,
 * having checked it, and asks Commerce about that one ULID. Neither module
 * reads the other's tables, and the answer is only ever about a row whose
 * ownership was established before the question was asked.
 *
 * `documentUrl` is a temporary signed URL for exactly that reason. It is
 * minted here, inside the render that verified ownership; the download it
 * points at is a separate request that Commerce could not authorize on its
 * own, and the signature carries the decision forward instead of asking
 * Commerce to make one it has no basis for.
 */
#[TypeScript]
final class RegistrationSettlementData extends Data
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $status,
        public readonly string $currency,
        public readonly string $totalGross,
        public readonly ?string $payUrl,
        public readonly ?string $invoiceNumber,
        public readonly ?string $issuedOn,
        public readonly ?string $dueOn,
        public readonly ?string $documentUrl,
    ) {}

    /**
     * Named `of*` rather than `from*`: laravel-data routes every public
     * static `from*` method through its magic-creation resolver
     * (.ai/rules/data.md).
     */
    public static function ofOrder(Order $order, ?Invoice $invoice, ?string $payUrl, ?string $documentUrl): self
    {
        return new self(
            orderId: $order->getKey(),
            status: $order->status->value,
            currency: $order->currency,
            totalGross: $order->total_gross,
            payUrl: $payUrl,
            invoiceNumber: $invoice?->invoice_number,
            issuedOn: $invoice?->issued_on->toDateString(),
            dueOn: $invoice?->due_on->toDateString(),
            documentUrl: $documentUrl,
        );
    }
}
