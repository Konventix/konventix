<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Data\InvoiceMailData;
use Konventix\Commerce\Models\Invoice;
use Konventix\Messaging\Actions\ResolveBrandTimezone;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\AttachmentData;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Operators\OperatorDateTimeFormat;
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
 *
 * ADR-0048: a paid invoice (`Invoice::isPaid()`) mails through
 * `commerce.invoice-paid` instead of `commerce.invoice-issued` — two
 * template keys rather than one branching on a `$paid` payload field, so
 * a per-brand override of either wording stays a plain template with no
 * conditional of its own (`.ai/rules/app-modules-src.md`).
 */
final class SendInvoiceMail
{
    private const string TEMPLATE_KEY_OPEN = 'commerce.invoice-issued';

    private const string TEMPLATE_KEY_PAID = 'commerce.invoice-paid';

    public function __construct(
        private readonly SendMessage $sendMessage,
        // One instance per class, not one per formatted value: the service
        // memoises the operator's chosen formats (review finding 6).
        private readonly OperatorDateTimeFormat $dates,
        private readonly ResolveBrandTimezone $brandTimezone,
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
            templateKey: $invoice->isPaid() ? self::TEMPLATE_KEY_PAID : self::TEMPLATE_KEY_OPEN,
            payload: new InvoiceMailData(
                invoiceNumber: $invoice->invoice_number,
                recipientName: $party->displayName(),
                sellerName: $invoice->seller->legalName,
                totalGross: $invoice->total_gross,
                currency: $invoice->currency,
                dueOn: $this->dates->date($invoice->due_on),
                termsDaysHint: (int) $invoice->issued_on->diffInDays($invoice->due_on),
                paid: $invoice->isPaid(),
                paidVia: $invoice->paid_via,
                // The day the payment landed, on the brand's clock (#131/#5): a
                // payment at 23:30 UTC is the next day in Berlin, and the mail
                // and the payer's bank statement should agree about which.
                // `due_on` above is a `date` column — a day, with no clock to
                // convert.
                paidAt: $invoice->paid_at === null
                    ? null
                    : $this->dates->date($invoice->paid_at, $this->brandTimezone->handle($invoice->brand_id)),
            ),
            recipientEmail: $party->email,
            // The mail speaks the language of the document it carries
            // (ADR-0068 §6), frozen at issuance.
            recipientLocale: $invoice->locale,
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
