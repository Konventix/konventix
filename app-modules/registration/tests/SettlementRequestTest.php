<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Registration\Actions\ApproveRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationSettlementRequested;
use Konventix\Registration\Models\RegistrationType;

function billedSubmission(): SubmitRegistrationData
{
    return new SubmitRegistrationData(
        organizationName: 'Musterfirma GmbH',
        billingRecipientName: 'Erika Musterfrau',
        billingEmail: 'rechnung@musterfirma.example',
        billingAddressLine: 'Musterstraße 1',
        billingPostalCode: '18439',
        billingCity: 'Stralsund',
        billingCountryCode: 'DE',
        billingVatId: null,
    );
}

it('emits RegistrationSettlementRequested at approval-confirmation for the exhibitor profile', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->invoiceSettled()->create();

    Event::fake([RegistrationSettlementRequested::class]);

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), billedSubmission());

    // Submission alone must not invoice an unapproved exhibitor.
    Event::assertNotDispatched(RegistrationSettlementRequested::class);
    expect($registration->status)->toBe(RegistrationStatus::Submitted)
        ->and($registration->payment_status)->toBe(PaymentStatus::Pending);

    app(ApproveRegistration::class)->handle($registration, (string) Str::ulid());

    Event::assertDispatched(RegistrationSettlementRequested::class, function (RegistrationSettlementRequested $event) use ($registration, $type): bool {
        expect($event->registrationId)->toBe($registration->id)
            ->and($event->settlement)->toBe('invoice')
            ->and($event->termsDays)->toBe(30)
            ->and($event->priceDisplay)->toBe('net')
            ->and($event->billingParty['organization_name'])->toBe('Musterfirma GmbH')
            ->and($event->billingParty['country_code'])->toBe('DE')
            ->and($event->lines)->toHaveCount(1)
            ->and($event->lines[0]['label'])->toBe($type->name)
            ->and($event->lines[0]['quantity'])->toBe(1)
            ->and($event->lines[0]['unit_amount_net'])->toBe('250.00')
            ->and($event->lines[0]['tax_class'])->toBe('standard');

        return true;
    });
});

it('emits RegistrationSettlementRequested at submission on an on_payment gate', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced(unitAmountNet: '30.00')
        ->create();

    Event::fake([RegistrationSettlementRequested::class]);

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), billedSubmission());

    // The visitor must be able to pay before anything confirms.
    expect($registration->status)->toBe(RegistrationStatus::Submitted);

    Event::assertDispatchedTimes(RegistrationSettlementRequested::class, 1);
    Event::assertDispatched(RegistrationSettlementRequested::class, fn (RegistrationSettlementRequested $event): bool => $event->settlement === 'instant'
        && $event->lines[0]['unit_amount_net'] === '30.00');
});

it('never requests settlement for free or external settlements', function (RegistrationSettlement $settlement): void {
    $this->withBrand();

    $type = RegistrationType::factory()
        ->settlement($settlement)
        ->confirmationGate(ConfirmationGate::OnSubmit)
        ->create();

    Event::fake([RegistrationSettlementRequested::class]);

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), billedSubmission());

    // on_submit / approval:none confirms immediately — and still no order.
    expect($registration->status)->toBe(RegistrationStatus::Confirmed);
    Event::assertNotDispatched(RegistrationSettlementRequested::class);
})->with([
    'free (ADR-0026)' => RegistrationSettlement::Free,
    'external (money moves off-platform)' => RegistrationSettlement::External,
]);

it('rejects an invoice-settled submission without a billing address', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->invoiceSettled()->create();

    app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), new SubmitRegistrationData(
        organizationName: 'Musterfirma GmbH',
        billingRecipientName: null,
        billingEmail: 'rechnung@musterfirma.example',
        billingAddressLine: null,
        billingPostalCode: null,
        billingCity: null,
        billingCountryCode: null,
        billingVatId: null,
    ));
})->throws(ValidationException::class);

it('refuses to request settlement for a priced settlement without a price declaration', function (): void {
    $this->withBrand();

    // Misconfigured type: invoice settlement, no price declaration.
    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Invoice)
        ->confirmationGate(ConfirmationGate::OnSubmit)
        ->create();

    app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), billedSubmission());
})->throws(RuntimeException::class, 'no price declaration');
