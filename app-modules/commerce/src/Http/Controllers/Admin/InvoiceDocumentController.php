<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Admin;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Models\Invoice;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the archived ZUGFeRD document for an issued invoice (ADR-0031).
 * The download is always the sealed archive copy — never a re-render — so
 * what the operator sends onward is byte-identical to what GoBD retention
 * holds.
 */
class InvoiceDocumentController
{
    public function download(string $invoice): StreamedResponse
    {
        Gate::authorize(Permission::ViewInvoices->value);

        /** @var Invoice $model */
        $model = Invoice::withoutBrandScope()->findOrFail($invoice);

        abort_if($model->document_path === null, 404, 'The invoice document has not been generated yet.');

        // The disk recorded on the row, not the currently configured one: a
        // document archived before a storage migration still downloads
        // (ADR-0043).
        return Storage::disk((string) $model->document_disk)->download(
            $model->document_path,
            $model->invoice_number.'.pdf',
        );
    }
}
