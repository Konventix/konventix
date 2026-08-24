<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Konventix\Commerce\Models\Invoice;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The registrant's own copy of an issued invoice.
 *
 * Authorization is the signature on the URL, not a check in here, and that
 * is the point rather than a shortcut: Commerce cannot tell whether this
 * account owns this invoice without reading Registration's tables (ADR-0004
 * forbids it, and it is the same boundary that leaves this module without a
 * GDPR handler). The link is minted by
 * `RegistrationSettlementData::ofOrder()` during a render that has already
 * established ownership, and expires, so the decision is made once by the
 * module entitled to make it.
 *
 * `auth` still applies: a signed link that leaked would otherwise be a
 * password-less document, and an invoice carries a billing address.
 *
 * Like the operator's download, this streams the sealed archive copy rather
 * than re-rendering (ADR-0031) — the registrant's PDF and the one GoBD
 * retention holds are byte-identical.
 */
final class MyInvoiceDocumentController
{
    public function __invoke(string $invoice): StreamedResponse
    {
        /** @var Invoice $model */
        $model = Invoice::query()->findOrFail($invoice);

        abort_if($model->document_path === null, 404, 'The invoice document has not been generated yet.');

        // The disk recorded on the row, not the currently configured one, so
        // a document archived before a storage migration still downloads
        // (ADR-0043).
        return Storage::disk((string) $model->document_disk)->download(
            $model->document_path,
            $model->invoice_number.'.pdf',
        );
    }
}
