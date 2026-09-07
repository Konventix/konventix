<?php

declare(strict_types=1);

use horstoeko\zugferd\ZugferdDocumentPdfReader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Actions\IssueInvoice;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Actions\SendInvoiceMail;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Data\PaymentSnapshotData;
use Konventix\Commerce\Documents\InvoiceDocumentRenderer;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Jobs\GenerateInvoiceDocument;
use Konventix\Commerce\Listeners\IssueInvoiceOnOrderPaid;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\InvoiceNumberRange;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Settlement\ProviderLabels;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Mail\TemplatedMail;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Operators\OperatorSettings;

/**
 * ADR-0048: `OrderPaid` issues the invoice — the same gapless per-brand
 * series (ADR-0031), with a payment snapshot — for a card-paid registration
 * and every cart order (ADR-0047), not only invoice-settled registrations.
 *
 * Helper names prefixed `paidInvoice*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function paidInvoiceBrand(): Brand
{
    $brand = test()->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

function paidInvoiceBillingParty(?string $name = 'Erika Musterfrau', ?string $email = 'erika@musterfrau.example'): BillingPartyData
{
    return BillingPartyData::ofAccount((string) $name, (string) $email);
}

/**
 * A pending order with one line, ready for `RecordPaymentSuccess` to settle
 * — the shape `IssueInvoiceOnOrderPaid` must turn into a Rechnung.
 */
function paidInvoiceOrder(string $settlesType = 'cart', ?BillingPartyData $billingParty = null): Order
{
    $order = Order::factory()->create([
        'settles_type' => $settlesType,
        'total_net' => '100.00',
        'total_tax' => '19.00',
        'total_gross' => '119.00',
        'billing_party' => $billingParty ?? paidInvoiceBillingParty(),
    ]);

    OrderLine::factory()->forOrder($order)->create([
        'label' => 'Rush Shirt',
        'unit_amount_net' => '100.00',
        'tax_rate' => '19.00',
        'line_net' => '100.00',
        'line_tax' => '19.00',
        'line_gross' => '119.00',
    ]);

    return $order;
}

function paidInvoicePayment(Order $order, string $provider = 'stripe'): Payment
{
    return Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => $provider,
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);
}

it('labels the null provider Testzahlung, matching what LocalHostedCheckoutAdapter actually stamps', function (): void {
    // SettlementAccount::PROVIDER_NULL — every dev/demo payment through
    // the no-PSP-connected adapter goes through this key, not 'local'.
    expect(ProviderLabels::labelFor('null'))->toBe('Testzahlung');
});

it('draws exactly one invoice number when IssueInvoice is called twice for the same order', function (): void {
    $brand = paidInvoiceBrand();
    // Refreshed: OrderFactory doesn't set tax_treatment/price_display, so
    // the in-memory model from create() misses the columns' DB defaults
    // ('standard'/'gross') until re-fetched — RecordPaymentSuccess always
    // re-fetches its own Order, but this test calls IssueInvoice directly.
    $order = paidInvoiceOrder()->fresh();
    $seller = app(OperatorSettings::class)->sellerForBrand($brand->id);
    $paid = new PaymentSnapshotData(
        paidAt: now()->toIso8601String(),
        paidVia: 'Stripe',
        provider: 'stripe',
        paymentReference: 'pi_test_1',
    );

    // Not a concurrency test — Pest runs single-threaded, so this cannot
    // reproduce two transactions actually racing the existence check. It
    // proves the idempotency half of the fix instead: two calls for the
    // same order, whether concurrent or sequential, must resolve to the
    // one invoice the first call issued and draw exactly one number. The
    // ordering that makes the *concurrent* case safe — the order row is
    // locked (`lockForUpdate`) inside the transaction, before the
    // existence check, not before the transaction opens — is read
    // directly off `IssueInvoice::handle()`'s source, not asserted here.
    //
    // The number range's half of that story *is* proven, by real racing
    // processes rather than by reading: `commerce:probe-invoice-numbering`
    // and `InvoiceNumberingProbeTest` (#74).
    $first = app(IssueInvoice::class)->handle($order, $order->billing_party, $seller, termsDays: 0, paid: $paid);
    $second = app(IssueInvoice::class)->handle($order, $order->billing_party, $seller, termsDays: 0, paid: $paid);

    expect($second->id)->toBe($first->id)
        ->and(Invoice::query()->where('order_id', $order->id)->count())->toBe(1);

    $range = InvoiceNumberRange::query()->sole();
    expect($range->counter)->toBe(1);
});

it('issues one invoice with a payment snapshot when a cart order is paid', function (): void {
    $brand = paidInvoiceBrand();
    $order = paidInvoiceOrder();
    $payment = paidInvoicePayment($order, 'stripe');

    app(RecordPaymentSuccess::class)->handle($payment);

    $invoice = Invoice::query()->where('order_id', $order->id)->sole();

    expect($invoice->due_on->toDateString())->toBe($invoice->issued_on->toDateString())
        ->and($invoice->paid_at)->not->toBeNull()
        ->and($invoice->paid_via)->toBe('Stripe')
        ->and($invoice->payment_reference)->toBe($payment->fresh()->external_id)
        ->and($invoice->total_gross)->toBe('119.00');
});

it('stays at one invoice when RecordPaymentSuccess is called twice on the same payment', function (): void {
    $brand = paidInvoiceBrand();
    $order = paidInvoiceOrder();
    $payment = paidInvoicePayment($order);

    app(RecordPaymentSuccess::class)->handle($payment);
    // The Payment-level guard (already Succeeded) short-circuits before
    // OrderPaid could ever be re-emitted — the same replay path a retried
    // webhook notification takes.
    app(RecordPaymentSuccess::class)->handle($payment->fresh());

    expect(Invoice::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('stays at one invoice when OrderPaid itself is replayed straight into the listener', function (): void {
    $brand = paidInvoiceBrand();
    $order = paidInvoiceOrder();
    $payment = paidInvoicePayment($order);

    app(RecordPaymentSuccess::class)->handle($payment);
    $firstInvoiceId = Invoice::query()->where('order_id', $order->id)->sole()->id;

    // IssueInvoice's own idempotency guard, exercised directly: even if
    // something replayed OrderPaid itself (not just the webhook), the
    // listener must still find the existing invoice and stop.
    app(IssueInvoiceOnOrderPaid::class)->handle(new OrderPaid(
        orderId: $order->id,
        brandId: $brand->id,
        settlesType: $order->settles_type,
        settlesId: $order->settles_id,
        amountPaid: $order->total_gross,
        currency: $order->currency,
        paidAt: now()->toIso8601String(),
        settlementMethod: 'stripe',
    ));

    $invoices = Invoice::query()->where('order_id', $order->id)->get();
    expect($invoices)->toHaveCount(1)
        ->and($invoices->first()->id)->toBe($firstInvoiceId);
});

it('does not issue a second invoice when an already invoice-settled order is paid later', function (): void {
    $brand = paidInvoiceBrand();

    $order = paidInvoiceOrder('registration');
    // Issued at placement, the way an `invoice`-settled registration
    // already gets one today (PlaceOrderFromSettlementRequest).
    $existingInvoice = Invoice::factory()->create(['order_id' => $order->id]);

    $payment = paidInvoicePayment($order, 'manual');

    app(RecordPaymentSuccess::class)->handle($payment);

    $invoices = Invoice::query()->where('order_id', $order->id)->get();

    expect($invoices)->toHaveCount(1)
        ->and($invoices->first()->id)->toBe($existingInvoice->id)
        // Untouched — an invoice already issued stays exactly as issued
        // (ADR-0031 immutability), even though the order is paid now.
        ->and($invoices->first()->paid_at)->toBeNull();
});

it('logs a warning and issues nothing when the paid order has no billing party', function (): void {
    paidInvoiceBrand();
    Log::spy();

    // No `billing_party` at all — the pre-ADR-0048 shape (or an operator-
    // booked order with no billing information).
    $order = Order::factory()->create(['settles_type' => 'cart']);
    OrderLine::factory()->forOrder($order)->create();
    $payment = paidInvoicePayment($order);

    app(RecordPaymentSuccess::class)->handle($payment);

    expect(Invoice::query()->where('order_id', $order->id)->exists())->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'billing party')
            && $context['order_id'] === $order->id);
});

it('archives a paid ZUGFeRD document with the prepaid amount, payment means 48 for a card PSP, and mails it as bezahlt', function (): void {
    $brand = paidInvoiceBrand();
    Storage::fake('invoices');
    Mail::fake();

    $order = paidInvoiceOrder();
    $payment = paidInvoicePayment($order, 'stripe');

    app(RecordPaymentSuccess::class)->handle($payment);

    $invoice = Invoice::query()->where('order_id', $order->id)->sole();

    (new GenerateInvoiceDocument($invoice->id, $brand->id))
        ->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    $invoice->refresh();
    expect($invoice->document_path)->not->toBeNull()
        ->and($invoice->paid_provider)->toBe('stripe');

    $bytes = Storage::disk('invoices')->get($invoice->document_path);
    $reader = ZugferdDocumentPdfReader::readAndGuessFromContent($bytes);

    $reader->getDocumentSummation(
        $grandTotal,
        $duePayable,
        $lineTotal,
        $charge,
        $allowance,
        $taxBasis,
        $tax,
        $rounding,
        $totalPrepaid,
    );

    expect($totalPrepaid)->toEqualWithDelta((float) $invoice->total_gross, 0.001)
        ->and($duePayable)->toEqualWithDelta(0.0, 0.001);

    expect($reader->firstGetDocumentPaymentMeans())->toBeTrue();

    $reader->getDocumentPaymentMeans(
        $typeCode,
        $information,
        $cardType,
        $cardId,
        $cardHolderName,
        $buyerIban,
        $payeeIban,
        $payeeAccountName,
        $payeePropId,
        $payeeBic,
    );

    // UNTDID 4461 "48" — bank card — matched off paid_provider (stripe),
    // not the translated paid_via label.
    expect($typeCode)->toBe('48')
        ->and($information)->toBe('Stripe');

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail): bool {
        expect($mail->envelope()->subject)->toContain('bezahlt')
            ->and($mail->attachments())->toHaveCount(1);

        return true;
    });
});

it('pins payment means 58 (SEPA credit transfer) for a manual bank-transfer payment', function (): void {
    $brand = paidInvoiceBrand();
    Storage::fake('invoices');
    Mail::fake();

    $order = paidInvoiceOrder();
    $payment = paidInvoicePayment($order, 'manual');

    app(RecordPaymentSuccess::class)->handle($payment);

    $invoice = Invoice::query()->where('order_id', $order->id)->sole();
    expect($invoice->paid_provider)->toBe('manual')
        ->and($invoice->paid_via)->toBe('Überweisung / manuell');

    (new GenerateInvoiceDocument($invoice->id, $brand->id))
        ->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    $bytes = Storage::disk('invoices')->get($invoice->refresh()->document_path);
    $reader = ZugferdDocumentPdfReader::readAndGuessFromContent($bytes);

    expect($reader->firstGetDocumentPaymentMeans())->toBeTrue();

    $reader->getDocumentPaymentMeans(
        $typeCode,
        $information,
        $cardType,
        $cardId,
        $cardHolderName,
        $buyerIban,
        $payeeIban,
        $payeeAccountName,
        $payeePropId,
        $payeeBic,
    );

    expect($typeCode)->toBe('58')
        ->and($information)->toBe('Überweisung / manuell');
});

it('shows invoicePaid true on /my/orders once the invoice carries a payment snapshot', function (): void {
    $brand = paidInvoiceBrand();
    $user = User::factory()->create();

    $order = paidInvoiceOrder('cart', paidInvoiceBillingParty($user->name, $user->email));
    $order->forceFill(['payer_user_id' => $user->getKey()])->save();

    $payment = paidInvoicePayment($order);
    app(RecordPaymentSuccess::class)->handle($payment);

    test()->actingAs($user)
        ->get('/my/orders')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('commerce/my/Index')
            ->where('orders.0.invoicePaid', true)
            ->where('orders.0.status', 'paid'));
});
