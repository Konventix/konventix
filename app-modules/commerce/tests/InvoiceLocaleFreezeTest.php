<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;
use Konventix\Commerce\Actions\IssueCreditNote;
use Konventix\Commerce\Actions\IssueInvoice;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Data\PaymentSnapshotData;
use Konventix\Commerce\Documents\CreditNoteDocumentRenderer;
use Konventix\Commerce\Documents\InvoiceDocumentRenderer;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Identity\Models\User;
use Konventix\Platform\Locales\Locales;
use Konventix\Platform\Operators\OperatorDateTimeFormat;
use Konventix\Platform\Operators\OperatorSettings;

/**
 * ADR-0068 §6: the locale an invoice or credit note was issued in is a fact
 * about the document. It is stamped at issuance and every later render reads
 * it, whatever locale the worker or the reader happens to be in.
 *
 * A rendered PDF is never string-matched (`.ai/rules/tickets.md`), so what
 * the renderer hands the Blade view is read through a view composer instead.
 *
 * Helper names prefixed `localeFreeze*`: Pest helpers are process-global.
 */
function localeFreezeOrder(array $attributes = []): Order
{
    $order = Order::factory()->paid()->create([
        'total_net' => '100.00',
        'total_tax' => '19.00',
        'total_gross' => '119.00',
        'billing_party' => BillingPartyData::ofAccount('Erika Musterfrau', 'erika@musterfrau.example'),
        ...$attributes,
    ]);

    OrderLine::factory()->forOrder($order)->create([
        'label' => 'Rush Shirt',
        'unit_amount_net' => '100.00',
        'tax_rate' => '19.00',
        'line_net' => '100.00',
        'line_tax' => '19.00',
        'line_gross' => '119.00',
    ]);

    // OrderFactory leaves tax_treatment/price_display to the column defaults.
    return $order->fresh();
}

function localeFreezeInvoice(Order $order, string $locale): Invoice
{
    return Invoice::factory()->create([
        'order_id' => $order->id,
        'total_net' => $order->total_net,
        'total_tax' => $order->total_tax,
        'total_gross' => $order->total_gross,
        'locale' => $locale,
    ]);
}

/**
 * The locale the given view was composed in, and the invoice/credit-note
 * heading as it read at that moment.
 *
 * @return object{locale: ?string, heading: ?string}
 */
function localeFreezeWatch(string $view, string $headingKey): object
{
    $seen = new stdClass;
    $seen->locale = null;
    $seen->heading = null;

    View::composer($view, function () use ($seen, $headingKey): void {
        $seen->locale = app()->getLocale();
        $seen->heading = __($headingKey, ['number' => 'X']);
    });

    return $seen;
}

it('renders the archived locale, not the current one', function (): void {
    $this->withBrand();
    $invoice = localeFreezeInvoice(localeFreezeOrder(), 'de');
    $seen = localeFreezeWatch('commerce::invoices.document', 'Invoice :number');

    app()->setLocale('en');

    $pdf = app(InvoiceDocumentRenderer::class)->render($invoice);

    expect(str_starts_with($pdf, '%PDF'))->toBeTrue()
        ->and($seen->locale)->toBe('de')
        ->and($seen->heading)->toBe('Rechnung X')
        // The worker's own locale is untouched afterwards.
        ->and(app()->getLocale())->toBe('en');
});

it('renders a credit note in its own archived locale', function (): void {
    $this->withBrand();
    $order = localeFreezeOrder();
    localeFreezeInvoice($order, 'de');
    $note = app(IssueCreditNote::class)->handle($order, IssueCreditNote::REASON_REFUND);
    $seen = localeFreezeWatch('commerce::credit-notes.document', 'Credit note :number');

    app()->setLocale('en');

    app(CreditNoteDocumentRenderer::class)->render($note->refresh());

    expect($seen->locale)->toBe('de')
        ->and($seen->heading)->toBe('Gutschrift X')
        ->and(app()->getLocale())->toBe('en');
});

it('issues the invoice in the payer’s effective locale and snapshots the payment label in it', function (): void {
    $brand = paidInvoiceBrand();
    $payer = User::factory()->create(['locale' => 'de']);
    $order = localeFreezeOrder(['payer_user_id' => $payer->id, 'status' => 'pending', 'paid_at' => null]);
    $seller = app(OperatorSettings::class)->sellerForBrand($brand->id);

    app()->setLocale('en');

    $invoice = app(IssueInvoice::class)->handle($order, $order->billing_party, $seller, termsDays: 0, paid: new PaymentSnapshotData(
        paidAt: now()->toIso8601String(),
        provider: 'manual',
        paymentReference: null,
    ));

    expect($invoice->refresh()->locale)->toBe('de')
        // The label is frozen with the row, in the row's own language.
        ->and($invoice->paid_via)->toBe('Überweisung / manuell')
        ->and(app()->getLocale())->toBe('en');
});

it('issues the invoice in the brand’s locale when the order names no payer', function (): void {
    $brand = paidInvoiceBrand();
    $brand->forceFill(['locale' => 'de'])->save();
    $order = localeFreezeOrder(['status' => 'pending', 'paid_at' => null]);
    $seller = app(OperatorSettings::class)->sellerForBrand($brand->id);

    $invoice = app(IssueInvoice::class)->handle($order, $order->billing_party, $seller, termsDays: 14);

    expect($invoice->refresh()->locale)->toBe('de');
});

it('issues a credit note in the language of the invoice it reverses', function (): void {
    $this->withBrand();
    $payer = User::factory()->create(['locale' => 'en']);
    $order = localeFreezeOrder(['payer_user_id' => $payer->id]);
    localeFreezeInvoice($order, 'de');

    $note = app(IssueCreditNote::class)->handle($order, IssueCreditNote::REASON_REFUND);

    expect(CreditNote::withoutBrandScope()->whereKey($note->id)->value('locale'))->toBe('de');
});

it('formats the amounts the way the document’s locale writes money', function (): void {
    $this->withBrand();
    $order = localeFreezeOrder();
    $invoice = localeFreezeInvoice($order, 'en');
    $order->setRelation('lines', OrderLine::withoutBrandScope()->where('order_id', $order->id)->get());

    $render = fn (string $locale): string => app(Locales::class)->within($locale, fn (): string => view('commerce::invoices.document', [
        'invoice' => $invoice,
        'order' => $order,
        'dates' => app(OperatorDateTimeFormat::class),
    ])->render());

    // By hand: 119.00 EUR is "€119.00" in English, and in German the comma
    // is the decimal mark with the sign after a no-break space.
    expect($render('en'))->toContain('€119.00')->toContain('19.00%')
        ->and($render('de'))->toContain("119,00\u{a0}€")->toContain("19,00\u{a0}%");
});
