<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Admin;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Models\CreditNote;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the archived ZUGFeRD document for an issued credit note
 * (ADR-0031, ADR-0048 point 2) — mirrors `InvoiceDocumentController`. Gated
 * on `ViewOrders`, the same permission `OrderAdminController::show()` gates
 * the credit notes it lists on the order page, since Commerce has no
 * separate "view credit notes" capability.
 */
class CreditNoteDocumentController
{
    public function download(string $creditNote): StreamedResponse
    {
        Gate::authorize(Permission::ViewOrders->value);

        /** @var CreditNote $model */
        $model = CreditNote::withoutBrandScope()->findOrFail($creditNote);

        abort_if($model->document_path === null, 404, 'The credit note document has not been generated yet.');

        // The disk recorded on the row, not the currently configured one: a
        // document archived before a storage migration still downloads
        // (ADR-0043).
        return Storage::disk((string) $model->document_disk)->download(
            $model->document_path,
            $model->credit_note_number.'.pdf',
        );
    }
}
