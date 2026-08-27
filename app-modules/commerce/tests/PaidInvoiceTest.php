<?php

declare(strict_types=1);

use horstoeko\zugferd\ZugferdDocumentPdfReader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Actions\SendInvoiceMail;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Documents\InvoiceDocumentRenderer;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Jobs\GenerateInvoiceDocument;
use Konventix\Commerce\Listeners\IssueInvoiceOnOrderPaid;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Mail\TemplatedMail;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

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

it('archives a paid ZUGFeRD document with the prepaid amount and payment means, and mails it as bezahlt', function (): void {
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
    expect($invoice->document_path)->not->toBeNull();

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

    expect($typeCode)->not->toBeEmpty()
        ->and($information)->toBe('Stripe');

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail): bool {
        expect($mail->envelope()->subject)->toContain('bezahlt')
            ->and($mail->attachments())->toHaveCount(1);

        return true;
    });
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
