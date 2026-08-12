<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Commerce\Actions\PlaceOrderFromSettlementRequest;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Commerce\Events\InvoiceIssued;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Commerce\Jobs\GenerateInvoiceDocument;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Actions\ApproveRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

function attachOperator(Brand $brand, bool $smallBusinessScheme = false): Brand
{
    $operatorFactory = Operator::factory();

    if ($smallBusinessScheme) {
        $operatorFactory = $operatorFactory->smallBusinessScheme();
    }

    $brand->operator()->associate($operatorFactory->create())->save();

    return $brand;
}

function exhibitorSubmission(?string $vatId = null, string $countryCode = 'DE'): SubmitRegistrationData
{
    return new SubmitRegistrationData(
        organizationName: 'Musterfirma GmbH',
        billingRecipientName: 'Erika Musterfrau',
        billingEmail: 'rechnung@musterfirma.example',
        billingAddressLine: 'Musterstraße 1',
        billingPostalCode: '18439',
        billingCity: 'Stralsund',
        billingCountryCode: $countryCode,
        billingVatId: $vatId,
    );
}

/**
 * Submit + approve one exhibitor registration (invoice / on_approval),
 * returning the confirmed registration.
 */
function settleExhibitorRegistration(SubmitRegistrationData $submission): Registration
{
    $type = RegistrationType::factory()->invoiceSettled()->create();

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), $submission);

    return app(ApproveRegistration::class)->handle($registration, (string) Str::ulid());
}

it('creates an order with lines and an invoice from an approved exhibitor registration', function (): void {
    $brand = attachOperator($this->withBrand());
    Queue::fake();

    Event::fake([OrderPlaced::class, InvoiceIssued::class]);

    $registration = settleExhibitorRegistration(exhibitorSubmission());

    $order = Order::query()->where('settles_id', $registration->id)->firstOrFail();

    expect($order->settles_type)->toBe('registration')
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->currency)->toBe('EUR')
        ->and($order->tax_treatment)->toBe(TaxTreatment::Standard)
        ->and($order->total_net)->toBe('250.00')
        ->and($order->total_tax)->toBe('47.50')
        ->and($order->total_gross)->toBe('297.50')
        ->and($order->price_display)->toBe('net');

    $lines = $order->lines()->get();
    expect($lines)->toHaveCount(1)
        ->and($lines[0]->tax_rate)->toBe('19.00')
        ->and($lines[0]->line_gross)->toBe('297.50');

    $invoice = Invoice::query()->where('order_id', $order->id)->firstOrFail();
    $prefix = Str::of($brand->slug)->replace('-', '')->upper()->substr(0, 6)->toString();

    expect($invoice->invoice_number)->toBe(sprintf('%s-%d-00001', $prefix, now()->year))
        ->and($invoice->due_on->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and($invoice->billing_party->organizationName)->toBe('Musterfirma GmbH')
        ->and($invoice->seller->legalName)->toBe($brand->operator->legal_name)
        ->and($invoice->total_gross)->toBe('297.50');

    Event::assertDispatched(OrderPlaced::class);
    Event::assertDispatched(InvoiceIssued::class, fn (InvoiceIssued $event): bool => $event->invoiceNumber === $invoice->invoice_number);
    Queue::assertPushed(GenerateInvoiceDocument::class, fn (GenerateInvoiceDocument $job): bool => $job->invoiceId === $invoice->id);
});

it('draws gapless sequential invoice numbers per brand and year', function (): void {
    attachOperator($this->withBrand());
    Queue::fake();

    $first = settleExhibitorRegistration(exhibitorSubmission());
    $second = settleExhibitorRegistration(exhibitorSubmission());

    $numbers = Invoice::query()->orderBy('invoice_number')->pluck('invoice_number');

    expect($numbers)->toHaveCount(2)
        ->and($numbers[0])->toEndWith('-00001')
        ->and($numbers[1])->toEndWith('-00002');
});

it('replays a settlement request without creating a second order', function (): void {
    attachOperator($this->withBrand());
    Queue::fake();

    $registration = settleExhibitorRegistration(exhibitorSubmission());

    $order = Order::query()->where('settles_id', $registration->id)->firstOrFail();

    // Replay: same payload again, as a queued retry would deliver it.
    app(PlaceOrderFromSettlementRequest::class)->handle(
        registrationId: $registration->id,
        brandId: $registration->brand_id,
        settlement: 'invoice',
        billingParty: [
            'organization_name' => 'Musterfirma GmbH',
            'recipient_name' => 'Erika Musterfrau',
            'email' => 'rechnung@musterfirma.example',
            'address_line' => 'Musterstraße 1',
            'postal_code' => '18439',
            'city' => 'Stralsund',
            'country_code' => 'DE',
            'vat_id' => null,
        ],
        lines: [['label' => 'Booth', 'quantity' => 1, 'unit_amount_net' => '250.00', 'tax_class' => 'standard']],
        priceDisplay: 'net',
        termsDays: 30,
    );

    expect(Order::query()->where('settles_id', $registration->id)->count())->toBe(1)
        ->and(Invoice::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('applies reverse charge through the chain for an EU billing party with a VAT ID', function (): void {
    attachOperator($this->withBrand());
    Queue::fake();

    $registration = settleExhibitorRegistration(exhibitorSubmission(vatId: 'ATU12345678', countryCode: 'AT'));

    $order = Order::query()->where('settles_id', $registration->id)->firstOrFail();
    $invoice = Invoice::query()->where('order_id', $order->id)->firstOrFail();

    expect($order->tax_treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and($order->total_tax)->toBe('0.00')
        ->and($order->total_gross)->toBe('250.00')
        ->and($invoice->tax_treatment)->toBe(TaxTreatment::ReverseCharge);
});

it('suppresses VAT under the operator §19 election through the chain', function (): void {
    attachOperator($this->withBrand(), smallBusinessScheme: true);
    Queue::fake();

    $registration = settleExhibitorRegistration(exhibitorSubmission());

    $order = Order::query()->where('settles_id', $registration->id)->firstOrFail();

    expect($order->tax_treatment)->toBe(TaxTreatment::SmallBusiness)
        ->and($order->total_tax)->toBe('0.00');
});

it('creates an order but no invoice for instant settlement', function (): void {
    attachOperator($this->withBrand());
    Queue::fake();

    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced(unitAmountNet: '30.00')
        ->create();

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), exhibitorSubmission());

    $order = Order::query()->where('settles_id', $registration->id)->firstOrFail();

    expect($order->total_gross)->toBe('35.70')
        ->and(Invoice::query()->where('order_id', $order->id)->exists())->toBeFalse();

    Queue::assertNotPushed(GenerateInvoiceDocument::class);
});
