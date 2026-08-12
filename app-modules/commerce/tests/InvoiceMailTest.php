<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Konventix\Commerce\Actions\SendInvoiceMail;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Documents\InvoiceDocumentRenderer;
use Konventix\Commerce\Jobs\GenerateInvoiceDocument;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Messaging\Mail\TemplatedMail;

/**
 * The last hop of the invoice-settled exhibitor flow (Phase 2 definition
 * of done): once the ZUGFeRD archive seals, the billing party receives the
 * sealed document by mail through Messaging.
 */
function mailedInvoice(?string $billingEmail = 'rechnung@musterfirma.example'): Invoice
{
    $order = Order::factory()->create();
    OrderLine::factory()->forOrder($order)->create();

    return Invoice::factory()->create([
        'order_id' => $order->id,
        'billing_party' => new BillingPartyData(
            organizationName: 'Musterfirma GmbH',
            recipientName: 'Erika Musterfrau',
            email: $billingEmail,
            addressLine: 'Musterstraße 1',
            postalCode: '18439',
            city: 'Stralsund',
            countryCode: 'DE',
            vatId: null,
        ),
    ]);
}

it('mails the sealed invoice document to the billing party after archival', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $invoice = mailedInvoice();

    (new GenerateInvoiceDocument($invoice->id, $brand->id))
        ->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    $invoice->refresh();
    expect($invoice->document_path)->not->toBeNull();

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($invoice): bool {
        $attachments = $mail->attachments();

        expect($mail->hasTo('rechnung@musterfirma.example'))->toBeTrue()
            ->and($mail->envelope()->subject)->toContain($invoice->invoice_number)
            ->and($attachments)->toHaveCount(1)
            ->and($attachments[0]->as)->toBe($invoice->invoice_number.'.pdf');

        return true;
    });
});

it('skips the mail without failing when the billing party has no email', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $invoice = mailedInvoice(billingEmail: null);

    (new GenerateInvoiceDocument($invoice->id, $brand->id))
        ->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    expect($invoice->refresh()->document_path)->not->toBeNull();
    Mail::assertNothingSent();
});

it('does not double-send on a job replay', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $invoice = mailedInvoice();
    $job = new GenerateInvoiceDocument($invoice->id, $brand->id);

    $job->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));
    $job->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    Mail::assertSentCount(1);
});
