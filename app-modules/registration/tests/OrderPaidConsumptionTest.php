<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Actions\ApproveRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationPaymentStatusChanged;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * Commerce's settlement listener runs for real in these tests, and issuing
 * an order needs the brand's operator as legal seller identity.
 */
function givenOperator(Brand $brand): Brand
{
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

function plainSubmission(): SubmitRegistrationData
{
    return new SubmitRegistrationData(
        organizationName: null,
        billingRecipientName: null,
        billingEmail: null,
    );
}

function orderPaidFor(Registration $registration, string $settlesType = 'registration'): OrderPaid
{
    return new OrderPaid(
        orderId: (string) Str::ulid(),
        brandId: $registration->brand_id,
        settlesType: $settlesType,
        settlesId: $registration->id,
        amountPaid: '35.70',
        currency: 'EUR',
        paidAt: now()->toIso8601String(),
        settlementMethod: 'manual',
    );
}

it('confirms an on_payment registration when its order is paid', function (): void {
    givenOperator($this->withBrand());

    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced()
        ->create();

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), plainSubmission());
    expect($registration->status)->toBe(RegistrationStatus::Submitted);

    Event::fake([RegistrationPaymentStatusChanged::class]);

    event(orderPaidFor($registration));

    $registration->refresh();
    expect($registration->payment_status)->toBe(PaymentStatus::Paid)
        ->and($registration->status)->toBe(RegistrationStatus::Confirmed);

    Event::assertDispatched(RegistrationPaymentStatusChanged::class, fn (RegistrationPaymentStatusChanged $event): bool => $event->registrationId === $registration->id
        && $event->previousStatus === 'pending'
        && $event->status === 'paid');
});

it('replays OrderPaid idempotently', function (): void {
    givenOperator($this->withBrand());

    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced()
        ->create();

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), plainSubmission());

    Event::fake([RegistrationPaymentStatusChanged::class]);

    event(orderPaidFor($registration));
    event(orderPaidFor($registration));

    Event::assertDispatchedTimes(RegistrationPaymentStatusChanged::class, 1);
});

it('ignores orders that settle something other than a registration', function (): void {
    givenOperator($this->withBrand());

    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced()
        ->create();

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), plainSubmission());

    event(orderPaidFor($registration, settlesType: 'resource_booking'));

    $registration->refresh();
    expect($registration->payment_status)->toBe(PaymentStatus::Pending)
        ->and($registration->status)->toBe(RegistrationStatus::Submitted);
});

it('holds confirmation until approval when payment lands first on an approval-required on_payment type', function (): void {
    givenOperator($this->withBrand());

    $type = RegistrationType::factory()
        ->requiringApproval()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced()
        ->create();

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), plainSubmission());

    event(orderPaidFor($registration));

    $registration->refresh();
    // Approval is a precondition, not a gate: paid, but not confirmed.
    expect($registration->payment_status)->toBe(PaymentStatus::Paid)
        ->and($registration->status)->toBe(RegistrationStatus::Submitted);

    $approved = app(ApproveRegistration::class)->handle($registration, (string) Str::ulid());

    expect($approved->status)->toBe(RegistrationStatus::Confirmed);
});

it('records payment without re-confirming an already-confirmed invoice registration', function (): void {
    givenOperator($this->withBrand());

    // Exhibitor profile: confirmed at approval, invoice paid later.
    $type = RegistrationType::factory()->invoiceSettled()->create();

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), new SubmitRegistrationData(
        organizationName: 'Musterfirma GmbH',
        billingRecipientName: 'Erika Musterfrau',
        billingEmail: 'rechnung@musterfirma.example',
        billingAddressLine: 'Musterstraße 1',
        billingPostalCode: '18439',
        billingCity: 'Stralsund',
        billingCountryCode: 'DE',
        billingVatId: null,
    ));

    $registration = app(ApproveRegistration::class)->handle($registration, (string) Str::ulid());
    expect($registration->status)->toBe(RegistrationStatus::Confirmed)
        ->and($registration->payment_status)->toBe(PaymentStatus::Pending);

    $confirmedAt = $registration->confirmed_at;

    event(orderPaidFor($registration));

    $registration->refresh();
    expect($registration->payment_status)->toBe(PaymentStatus::Paid)
        ->and($registration->status)->toBe(RegistrationStatus::Confirmed)
        ->and($registration->confirmed_at?->equalTo($confirmedAt))->toBeTrue();
});
