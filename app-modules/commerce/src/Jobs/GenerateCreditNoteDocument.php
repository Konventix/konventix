<?php

declare(strict_types=1);

namespace Konventix\Commerce\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Konventix\Commerce\Actions\SendCreditNoteMail;
use Konventix\Commerce\Documents\CreditNoteDocumentRenderer;
use Konventix\Commerce\Models\CreditNote;

/**
 * Renders and archives the hybrid ZUGFeRD document for an issued credit note
 * (ADR-0031, ADR-0048 point 2) — mirrors `GenerateInvoiceDocument` exactly:
 * a Gutschrift gets a document and a mail, like an invoice. Dispatched by
 * `IssueCreditNote` after its transaction commits.
 *
 * Idempotent: a credit note whose document fields are already sealed is
 * left untouched — the archive is written exactly once, and a replay never
 * overwrites it (GoBD). The archive path is brand/year-sharded and keyed by
 * the credit note number, on the same `invoices` disk an invoice archives
 * to — one archive, one retention rule, for both document kinds.
 */
final class GenerateCreditNoteDocument implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * Rendering is CPU-bound and deterministic; retries only help against
     * transient storage failures.
     */
    public int $tries = 3;

    public function __construct(
        public readonly string $creditNoteId,
        public readonly string $brandId,
    ) {}

    public function handle(CreditNoteDocumentRenderer $renderer, SendCreditNoteMail $sendCreditNoteMail): void
    {
        /** @var CreditNote|null $creditNote */
        $creditNote = CreditNote::withoutBrandScope()
            ->where('brand_id', $this->brandId)
            ->whereKey($this->creditNoteId)
            ->first();

        if ($creditNote === null || $creditNote->document_path !== null) {
            return;
        }

        $bytes = $renderer->render($creditNote);

        $path = sprintf(
            '%s/%s/%s.pdf',
            $this->brandId,
            $creditNote->issued_on->format('Y'),
            $creditNote->credit_note_number,
        );

        // Same disk pointer as invoices (ADR-0043) — the disk is sealed onto
        // the row beside the path, so an archive stays readable after the
        // pointer moves.
        $disk = (string) config('filesystems.invoices_disk', 'invoices');

        Storage::disk($disk)->put($path, $bytes);

        $creditNote->forceFill([
            'document_path' => $path,
            'document_disk' => $disk,
            'document_sha256' => hash('sha256', $bytes),
            'document_generated_at' => now(),
        ])->save();

        // The sealed archive exists — deliver it. Behind the document_path
        // guard above, so a job replay never double-sends.
        $sendCreditNoteMail->handle($creditNote);
    }
}
