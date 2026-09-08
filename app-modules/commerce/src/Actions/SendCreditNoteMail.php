<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Data\CreditNoteMailData;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\AttachmentData;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Operators\OperatorDateTimeFormat;
use Spatie\LaravelData\DataCollection;

/**
 * Sends the issued credit note to its billing party through Messaging
 * (ADR-0048 point 2) — mirrors `SendInvoiceMail` exactly: a Gutschrift is
 * mailed the same way a Rechnung is. Called by `GenerateCreditNoteDocument`
 * once the ZUGFeRD archive is sealed, so the attachment is always the
 * byte-identical archive copy, never a re-render.
 *
 * The recipient is the billing party from the credit note's own snapshot
 * (taken from the invoice it corrects at issuance) — `recipientId` is
 * therefore null and the explicit email carries the address, the same
 * designed shape `SendMessageRequest`'s docblock describes. The brand
 * travels explicitly: this runs in a queue worker where no brand is
 * ambient.
 *
 * A billing party without an email is logged and skipped, not an error —
 * same as `SendInvoiceMail`.
 */
final class SendCreditNoteMail
{
    private const string TEMPLATE_KEY = 'commerce.credit-note-issued';

    public function __construct(
        private readonly SendMessage $sendMessage,
        // One instance per class, not one per formatted value: the service
        // memoises the operator's chosen formats (review finding 6).
        private readonly OperatorDateTimeFormat $dates,
    ) {}

    public function handle(CreditNote $creditNote): void
    {
        $party = $creditNote->billing_party;

        if ($party->email === null) {
            Log::warning('Credit note has no billing email; skipping credit note mail.', [
                'credit_note_id' => $creditNote->getKey(),
                'credit_note_number' => $creditNote->credit_note_number,
            ]);

            return;
        }

        if ($creditNote->document_path === null) {
            // Guarded by the caller; stated here so a future call site
            // cannot silently mail a credit note without its document.
            Log::warning('Credit note document not yet archived; skipping credit note mail.', [
                'credit_note_id' => $creditNote->getKey(),
            ]);

            return;
        }

        $invoiceNumber = $creditNote->invoice?->invoice_number;

        $this->sendMessage->handle(new SendMessageRequest(
            recipientId: null,
            templateKey: self::TEMPLATE_KEY,
            payload: new CreditNoteMailData(
                creditNoteNumber: $creditNote->credit_note_number,
                invoiceNumber: $invoiceNumber,
                recipientName: $party->displayName(),
                sellerName: $creditNote->seller->legalName,
                totalGross: $creditNote->total_gross,
                currency: $creditNote->currency,
                reasonLabelDe: $this->reasonLabelDe($creditNote->reason_code),
                reasonLabelEn: $this->reasonLabelEn($creditNote->reason_code),
                issuedOn: $this->dates->date($creditNote->issued_on),
            ),
            recipientEmail: $party->email,
            recipientLocale: 'de',
            brandId: $creditNote->brand_id,
            attachments: new DataCollection(AttachmentData::class, [
                new AttachmentData(
                    disk: (string) $creditNote->document_disk,
                    path: $creditNote->document_path,
                    as: $creditNote->credit_note_number.'.pdf',
                    mime: 'application/pdf',
                ),
            ]),
        ));
    }

    /**
     * Cancellation (ADR-0057 point 4: the deadline sweep's credit note, for
     * an order whose reversed payment went unpaid) is neither a refund nor
     * a transfer — the receivable was written off, nothing was paid back —
     * so it gets its own word rather than falling into the "anything else"
     * default `reasonLabelDe`/`En` used to be.
     */
    private function reasonLabelDe(string $reasonCode): string
    {
        return match ($reasonCode) {
            IssueCreditNote::REASON_TRANSFER => 'Umbuchung',
            IssueCreditNote::REASON_CANCELLATION => 'Stornierung',
            default => 'Erstattung',
        };
    }

    private function reasonLabelEn(string $reasonCode): string
    {
        return match ($reasonCode) {
            IssueCreditNote::REASON_TRANSFER => 'Transfer',
            IssueCreditNote::REASON_CANCELLATION => 'Cancellation',
            default => 'Refund',
        };
    }
}
