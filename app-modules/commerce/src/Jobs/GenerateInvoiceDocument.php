<?php

declare(strict_types=1);

namespace Konventix\Commerce\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Konventix\Commerce\Actions\SendInvoiceMail;
use Konventix\Commerce\Documents\InvoiceDocumentRenderer;
use Konventix\Commerce\Models\Invoice;

/**
 * Renders and archives the hybrid ZUGFeRD document for an issued invoice
 * (ADR-0031). Dispatched by `IssueInvoice` after its transaction commits.
 *
 * Idempotent: an invoice whose document fields are already sealed is left
 * untouched — the archive is written exactly once, and a replay never
 * overwrites it (GoBD: the issued document is stored unaltered). The
 * archive path is brand/year-sharded and keyed by the invoice number, on
 * the dedicated `invoices` disk that production points at durable storage.
 */
final class GenerateInvoiceDocument implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * Rendering is CPU-bound and deterministic; retries only help against
     * transient storage failures.
     */
    public int $tries = 3;

    public function __construct(
        public readonly string $invoiceId,
        public readonly string $brandId,
    ) {}

    public function handle(InvoiceDocumentRenderer $renderer, SendInvoiceMail $sendInvoiceMail): void
    {
        /** @var Invoice|null $invoice */
        $invoice = Invoice::withoutBrandScope()
            ->where('brand_id', $this->brandId)
            ->whereKey($this->invoiceId)
            ->first();

        if ($invoice === null || $invoice->document_path !== null) {
            return;
        }

        $bytes = $renderer->render($invoice);

        $path = sprintf(
            '%s/%s/%s.pdf',
            $this->brandId,
            $invoice->issued_on->format('Y'),
            $invoice->invoice_number,
        );

        // Where NEW archives land (ADR-0043); the disk is sealed onto the row
        // beside the path, so an archive stays readable after the pointer
        // moves — ADR-0031 requires it to survive storage migrations.
        $disk = (string) config('filesystems.invoices_disk', 'invoices');

        Storage::disk($disk)->put($path, $bytes);

        $invoice->forceFill([
            'document_path' => $path,
            'document_disk' => $disk,
            'document_sha256' => hash('sha256', $bytes),
            'document_generated_at' => now(),
        ])->save();

        // The sealed archive exists — deliver it (the last hop of the
        // exhibitor flow). Behind the document_path guard above, so a job
        // replay never double-sends.
        $sendInvoiceMail->handle($invoice);
    }
}
