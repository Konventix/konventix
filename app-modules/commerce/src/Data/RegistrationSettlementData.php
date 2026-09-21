<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Settlement\PayLink;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How one registration was settled, as the person who registered sees it
 * (ADR-0009): what it costs, whether it is paid, and the invoice if one was
 * issued.
 *
 * Commerce cannot find this by itself. The standalone list that can lives at
 * `/my/orders`, reading `payer_user_id` (ADR-0044).
 *
 * `documentUrl` is a temporary signed URL for exactly that reason. It is
 * minted here, inside the render that verified ownership; the download it
 * points at is a separate request that Commerce could not authorize on its
 * own, and the signature carries the decision forward instead of asking
 * Commerce to make one it has no basis for.
 *
 * `payUrl` and `repayDueOn` are `Settlement\PayLink`'s answers (GitLab
 * #197), resolved here rather than handed in by the slot: this page used
 * to inline `status === Pending`, which hid the link on a `payment_reversed`
 * order that ADR-0057 point 3 makes payable again — while `/my/orders` and
 * both mails, all three reading `PayLink`, still offered it. Four surfaces,
 * one rule. `repayDueOn` is the day that money is owed back by (point 4);
 * a link without it does not say when the seat goes away.
 *
 * `orderUrl` is the order's own page, `/my/orders/{order}` (bugfix round
 * 2026-09-21, Task 17b): the slot used to print the invoice number as text
 * and leave the reader to find the order in the list. It is set only when
 * the order has a payer — `MyOrdersController::show()` 404s an order whose
 * `payer_user_id` is null (a manual booking, or one placed before
 * ADR-0044), and a link to a 404 is worse than none.
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
        public readonly ?string $repayDueOn,
        public readonly ?string $invoiceNumber,
        public readonly ?string $issuedOn,
        public readonly ?string $dueOn,
        public readonly ?string $documentUrl,
        public readonly ?string $orderUrl,
    ) {}

    /**
     * Named `of*` rather than `from*`: laravel-data routes every public
     * static `from*` method through its magic-creation resolver
     * (.ai/rules/data.md).
     */
    public static function ofOrder(Order $order, ?Invoice $invoice, ?string $documentUrl): self
    {
        $payLink = app(PayLink::class);
        $link = $payLink->forOrder($order);

        return new self(
            orderId: $order->getKey(),
            status: $order->status->value,
            currency: $order->currency,
            totalGross: $order->total_gross,
            // Root-relative: this page is rendered on the brand's own host
            // and an absolute URL would pin the link to whichever of the
            // brand's domains the request happened to arrive on (ADR-0001).
            payUrl: $link === null ? null : route($link['route'], $link['parameters'], absolute: false),
            repayDueOn: $payLink->repayDueOnFor($order),
            invoiceNumber: $invoice?->invoice_number,
            issuedOn: $invoice?->issued_on->toDateString(),
            dueOn: $invoice?->due_on->toDateString(),
            documentUrl: $documentUrl,
            orderUrl: $order->payer_user_id === null
                ? null
                : route('commerce.mine.show', ['order' => $order->getKey()], absolute: false),
        );
    }
}
