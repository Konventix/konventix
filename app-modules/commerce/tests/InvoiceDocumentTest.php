<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Konventix\Commerce\Actions\SendInvoiceMail;
use Konventix\Commerce\Documents\InvoiceDocumentRenderer;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Commerce\Jobs\GenerateInvoiceDocument;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;

function issuedInvoice(TaxTreatment $treatment = TaxTreatment::Standard): Invoice
{
    $order = Order::factory()->create([
        'total_net' => '250.00',
        'total_tax' => $treatment === TaxTreatment::Standard ? '47.50' : '0.00',
        'total_gross' => $treatment === TaxTreatment::Standard ? '297.50' : '250.00',
        'tax_treatment' => $treatment,
    ]);

    OrderLine::factory()->forOrder($order)->create([
        'label' => 'Messestand 3×3m',
        'unit_amount_net' => '250.00',
        'tax_rate' => $treatment === TaxTreatment::Standard ? '19.00' : '0.00',
        'line_net' => '250.00',
        'line_tax' => $treatment === TaxTreatment::Standard ? '47.50' : '0.00',
        'line_gross' => $treatment === TaxTreatment::Standard ? '297.50' : '250.00',
    ]);

    return Invoice::factory()->create([
        'order_id' => $order->id,
        'total_net' => '250.00',
        'total_tax' => $order->total_tax,
        'total_gross' => $order->total_gross,
        'tax_treatment' => $treatment,
    ]);
}

it('renders, archives and seals a hybrid ZUGFeRD document exactly once', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $invoice = issuedInvoice();

    $job = new GenerateInvoiceDocument($invoice->id, $brand->id);
    $job->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    $invoice->refresh();

    $expectedPath = sprintf('%s/%s/%s.pdf', $brand->id, $invoice->issued_on->format('Y'), $invoice->invoice_number);

    expect($invoice->document_path)->toBe($expectedPath)
        ->and($invoice->document_generated_at)->not->toBeNull();

    Storage::disk('invoices')->assertExists($expectedPath);

    $bytes = Storage::disk('invoices')->get($expectedPath);

    expect(hash('sha256', $bytes))->toBe($invoice->document_sha256)
        // A real PDF with the Factur-X XML attachment embedded (ADR-0031).
        ->and(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and(str_contains($bytes, 'factur-x.xml'))->toBeTrue();

    // Replay must not rewrite the archived document (GoBD).
    $sealedHash = $invoice->document_sha256;
    $job->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    expect($invoice->refresh()->document_sha256)->toBe($sealedHash);
});

it('renders the §19 UStG variant without a VAT line', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $invoice = issuedInvoice(TaxTreatment::SmallBusiness);

    (new GenerateInvoiceDocument($invoice->id, $brand->id))
        ->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    expect($invoice->refresh()->document_path)->not->toBeNull();
});
