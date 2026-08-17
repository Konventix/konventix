<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Data\InvoiceMailData;
use Konventix\Commerce\Models\Invoice;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\AttachmentData;
use Konventix\Messaging\Data\SendMessageRequest;
use Spatie\LaravelData\DataCollection;

/**
 * Sends the issued invoice to its billing party through Messaging — the
 * last hop of the invoice-settled exhibitor flow (Phase 2 definition of
 * done). Called by `GenerateInvoiceDocument` once the ZUGFeRD archive is
 * sealed, so the attachment is always the byte-identical archive copy
 * (ADR-0031), never a re-render.
 *
 * The recipient is the billing party from the invoice snapshot, which may
 * have no user account behind it — `recipientId` is therefore null and the
 * explicit email carries the address (see SendMessageRequest's docblock;
 * this is the designed case, not a workaround). The brand travels
 * explicitly: this runs in a queue worker where no brand is ambient.
 *
 * A billing party without an email is logged and skipped, not an error:
 * `settlement: invoice` submissions require one (`guardBillingParty`), but
 * an operator-created order might not — the invoice still exists and can
 * be downloaded from the console.
 */
final class SendInvoiceMail
{
    private const string TEMPLATE_KEY = 'commerce.invoice-issued';

    public function __construct(
        private readonly SendMessage $sendMessage,
    ) {}

    public function handle(Invoice $invoice): void
    {
        $party = $invoice->billing_party;

        if ($party->email === null) {
            Log::warning('Invoice has no billing email; skipping invoice mail.', [
                'invoice_id' => $invoice->getKey(),
                'invoice_number' => $invoice->invoice_number,
            ]);

            return;
        }

        if ($invoice->document_path === null) {
            // Guarded by the caller; stated here so a future call site
            // cannot silently mail an invoice without its document.
            Log::warning('Invoice document not yet archived; skipping invoice mail.', [
                'invoice_id' => $invoice->getKey(),
            ]);

            return;
        }

        $this->sendMessage->handle(new SendMessageRequest(
            recipientId: null,
            templateKey: self::TEMPLATE_KEY,
            payload: new InvoiceMailData(
                invoiceNumber: $invoice->invoice_number,
                recipientName: $party->displayName(),
                sellerName: $invoice->seller->legalName,
                totalGross: $invoice->total_gross,
                currency: $invoice->currency,
                dueOn: $invoice->due_on->format('d.m.Y'),
                termsDaysHint: (int) $invoice->issued_on->diffInDays($invoice->due_on),
            ),
            recipientEmail: $party->email,
            recipientLocale: 'de',
            brandId: $invoice->brand_id,
            attachments: new DataCollection(AttachmentData::class, [
                new AttachmentData(
                    disk: (string) $invoice->document_disk,
                    path: $invoice->document_path,
                    as: $invoice->invoice_number.'.pdf',
                    mime: 'application/pdf',
                ),
            ]),
        ));
    }
}
