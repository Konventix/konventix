<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Commerce\Events\OrderLineSettled;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * `OrderLineSettled` (ADR-0047 point 4, Task 11): the counterpart of
 * Commerce's `OrderPaid` for a cart order, whose lines an order-level event
 * alone cannot tell apart. Registration confirms exactly the registrations
 * a cart checkout actually paid for, and no longer confirms a cart order
 * from `OrderPaid` alone.
 *
 * Helper names prefixed `lineSettled*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function lineSettledOperator(Brand $brand): Brand
{
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

function lineSettledSubmission(): SubmitRegistrationData
{
    return new SubmitRegistrationData(
        organizationName: null,
        billingRecipientName: null,
        billingEmail: null,
    );
}

function lineSettledRegistration(): Registration
{
    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced()
        ->create();

    return app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), lineSettledSubmission());
}

function lineSettledEvent(Registration $registration, string $kind = 'registration', ?string $refId = null): OrderLineSettled
{
    return new OrderLineSettled(
        orderId: (string) Str::ulid(),
        brandId: $registration->brand_id,
        kind: $kind,
        refId: $refId ?? $registration->id,
        orderRequestKey: (string) Str::ulid(),
        amountGross: '35.70',
        currency: 'EUR',
        paidAt: now()->toIso8601String(),
        settlementAccountId: null,
    );
}

it('confirms an on_payment registration when its cart line settles', function (): void {
    lineSettledOperator($this->withBrand());
    $registration = lineSettledRegistration();
    expect($registration->status)->toBe(RegistrationStatus::Submitted)
        ->and($registration->payment_status)->toBe(PaymentStatus::Pending);

    event(lineSettledEvent($registration));

    $registration->refresh();
    expect($registration->payment_status)->toBe(PaymentStatus::Paid)
        ->and($registration->status)->toBe(RegistrationStatus::Confirmed);
});

it('ignores a product-kind line', function (): void {
    lineSettledOperator($this->withBrand());
    $registration = lineSettledRegistration();

    // A product line's refId happens to point at this registration's id —
    // deliberately, to prove the guard is on `kind`, not on whether refId
    // resolves to something.
    event(lineSettledEvent($registration, kind: 'product'));

    $registration->refresh();
    expect($registration->payment_status)->toBe(PaymentStatus::Pending)
        ->and($registration->status)->toBe(RegistrationStatus::Submitted);
});

it('is a no-op for an unrelated registration id', function (): void {
    lineSettledOperator($this->withBrand());
    $registration = lineSettledRegistration();

    event(lineSettledEvent($registration, refId: (string) Str::ulid()));

    $registration->refresh();
    expect($registration->payment_status)->toBe(PaymentStatus::Pending);
});

it('no longer confirms a cart order from OrderPaid alone', function (): void {
    lineSettledOperator($this->withBrand());
    $registration = lineSettledRegistration();

    event(new OrderPaid(
        orderId: (string) Str::ulid(),
        brandId: $registration->brand_id,
        settlesType: 'cart',
        settlesId: (string) Str::ulid(),
        amountPaid: '83.30',
        currency: 'EUR',
        paidAt: now()->toIso8601String(),
        settlementMethod: 'stripe',
    ));

    $registration->refresh();
    expect($registration->payment_status)->toBe(PaymentStatus::Pending)
        ->and($registration->status)->toBe(RegistrationStatus::Submitted);
});
