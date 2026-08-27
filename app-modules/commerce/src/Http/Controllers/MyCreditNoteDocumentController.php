<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Konventix\Commerce\Models\CreditNote;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The payer's own copy of an issued credit note (ADR-0048 point 2) — mirrors
 * `MyInvoiceDocumentController`.
 *
 * Unlike the invoice controller, ownership is checked here explicitly
 * against the order's `payer_user_id`, not left entirely to the signature:
 * a credit note has no per-render minting site that has already established
 * ownership the way `RegistrationSettlementData::ofOrder()` does for an
 * invoice — `MyOrderData::ofOrder()` mints this link from the same "my
 * orders" list every payer can reach, so the explicit check is the one
 * place ownership is actually proven.
 *
 * `signed:relative` plus `auth` still apply for the same reason as the
 * invoice route: a leaked signed link would otherwise be a password-less
 * document, and a credit note carries a billing address.
 *
 * Like the invoice download, this streams the sealed archive copy rather
 * than re-rendering (ADR-0031) — the payer's PDF and the one GoBD retention
 * holds are byte-identical.
 */
final class MyCreditNoteDocumentController
{
    public function __invoke(Request $request, string $creditNote): StreamedResponse
    {
        /** @var CreditNote $model */
        $model = CreditNote::query()->with('order')->findOrFail($creditNote);

        abort_if(
            $model->order === null || $model->order->payer_user_id !== (string) $request->user()->getAuthIdentifier(),
            404,
        );

        abort_if($model->document_path === null, 404, 'The credit note document has not been generated yet.');

        // The disk recorded on the row, not the currently configured one, so
        // a document archived before a storage migration still downloads
        // (ADR-0043).
        return Storage::disk((string) $model->document_disk)->download(
            $model->document_path,
            $model->credit_note_number.'.pdf',
        );
    }
}
