<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Events\OrderLineCancelled;
use Konventix\Commerce\Events\OrderLineReversed;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Events\PaymentReversed;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationPaymentStatusChanged;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * ADR-0057 points 5: a reversal revives the receivable without touching the
 * seat. Registration moves `payment_status` back to `pending` and leaves
 * `status: confirmed` and the held quota exactly where they are — ADR-0005's
 * two independent fields exist for this case. Only the *deadline*, arriving
 * as `OrderCancelled` with reason `reversal_unpaid`, cancels the contractual
 * act; a whole-order refund does the same, which is the gap ADR-0037 left.
 *
 * The listeners are registered at boot
 * (`RegistrationServiceProvider::registerListeners()`), so these tests
 * dispatch the real Commerce events and exercise the real wiring. No
 * Commerce code beyond the event classes is needed: Registration never reads
 * a reversal row (ADR-0004).
 *
 * Helper names prefixed `reversal*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function reversalOperator(Brand $brand): Brand
{
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

function reversalSubmission(): SubmitRegistrationData
{
    return new SubmitRegistrationData(
        organizationName: null,
        billingRecipientName: null,
        billingEmail: null,
    );
}

/**
 * A confirmed, paid registration holding one unit of `$quota` — the state
 * every reversal in this file arrives at. Paid through the real `OrderPaid`
 * listener rather than a forced column write, so `payment_status` got there
 * the way production gets it there.
 */
function reversalPaidRegistration(Quota $quota): Registration
{
    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced()
        ->create();

    $type->quotas()->attach($quota);

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), reversalSubmission());

    event(new OrderPaid(
        orderId: (string) Str::ulid(),
        brandId: $registration->brand_id,
        settlesType: 'registration',
        settlesId: $registration->id,
        amountPaid: '35.70',
        currency: 'EUR',
        paidAt: now()->toIso8601String(),
        settlementMethod: 'manual',
    ));

    return $registration->refresh();
}

function reversalPaymentReversedEvent(Registration $registration, string $settlesType = 'registration'): PaymentReversed
{
    return new PaymentReversed(
        reversalId: (string) Str::ulid(),
        paymentId: (string) Str::ulid(),
        orderId: (string) Str::ulid(),
        brandId: $registration->brand_id,
        settlesType: $settlesType,
        settlesId: $registration->id,
        kind: 'return',
        amount: '35.70',
        currency: 'EUR',
        settlementMethod: 'stripe',
        settlementAccountId: null,
        providerReference: 'dp_'.Str::random(12),
        reasonCode: 'insufficient_funds',
        repayDueOn: now()->addDays(7)->toDateString(),
        reversedAt: now()->toIso8601String(),
    );
}

function reversalOrderLineReversedEvent(Registration $registration, string $kind = 'registration', ?string $refId = null): OrderLineReversed
{
    return new OrderLineReversed(
        orderId: (string) Str::ulid(),
        brandId: $registration->brand_id,
        kind: $kind,
        refId: $refId ?? $registration->id,
        orderRequestKey: (string) Str::ulid(),
        amountGross: '35.70',
        currency: 'EUR',
        reversedAt: now()->toIso8601String(),
        settlementAccountId: null,
    );
}

function reversalOrderCancelledEvent(
    Registration $registration,
    string $reasonCode = 'reversal_unpaid',
    string $settlesType = 'registration',
): OrderCancelled {
    return new OrderCancelled(
        orderId: (string) Str::ulid(),
        brandId: $registration->brand_id,
        settlesType: $settlesType,
        settlesId: $registration->id,
        cancelledByUserId: null,
        reasonCode: $reasonCode,
        creditNoteId: null,
        cancelledAt: now()->toIso8601String(),
    );
}

function reversalOrderLineCancelledEvent(
    Registration $registration,
    string $kind = 'registration',
    string $reasonCode = 'reversal_unpaid',
    ?string $refId = null,
): OrderLineCancelled {
    return new OrderLineCancelled(
        orderId: (string) Str::ulid(),
        brandId: $registration->brand_id,
        kind: $kind,
        refId: $refId ?? $registration->id,
        orderRequestKey: (string) Str::ulid(),
        reasonCode: $reasonCode,
        cancelledByUserId: null,
        cancelledAt: now()->toIso8601String(),
    );
}

function reversalOrderRefundedEvent(Registration $registration, bool $whole = true): OrderRefunded
{
    return new OrderRefunded(
        orderId: (string) Str::ulid(),
        brandId: $registration->brand_id,
        settlesType: 'registration',
        settlesId: $registration->id,
        amountRefunded: $whole ? '35.70' : '10.00',
        currency: 'EUR',
        creditNoteId: (string) Str::ulid(),
        reasonCode: 'goodwill',
        refundedAt: now()->toIso8601String(),
        whole: $whole,
    );
}

it('puts the payment status back to pending on PaymentReversed and leaves the seat alone', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    expect($registration->payment_status)->toBe(PaymentStatus::Paid)
        ->and($registration->status)->toBe(RegistrationStatus::Confirmed)
        ->and($quota->fresh()->consumed)->toBe(1);

    Event::fake([RegistrationPaymentStatusChanged::class]);

    event(reversalPaymentReversedEvent($registration));

    $registration->refresh();

    // ADR-0057 point 5: the receivable revives, the seat does not move.
    expect($registration->payment_status)->toBe(PaymentStatus::Pending)
        ->and($registration->status)->toBe(RegistrationStatus::Confirmed)
        ->and($quota->fresh()->consumed)->toBe(1);

    Event::assertDispatched(
        RegistrationPaymentStatusChanged::class,
        fn (RegistrationPaymentStatusChanged $event): bool => $event->registrationId === $registration->id
            && $event->previousStatus === 'paid'
            && $event->status === 'pending',
    );
});

it('does the same per line on OrderLineReversed', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    Event::fake([RegistrationPaymentStatusChanged::class]);

    event(reversalOrderLineReversedEvent($registration));

    $registration->refresh();

    expect($registration->payment_status)->toBe(PaymentStatus::Pending)
        ->and($registration->status)->toBe(RegistrationStatus::Confirmed)
        ->and($quota->fresh()->consumed)->toBe(1);

    Event::assertDispatched(RegistrationPaymentStatusChanged::class);
});

it('ignores a PaymentReversed that settles something other than a registration', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    event(reversalPaymentReversedEvent($registration, settlesType: 'resource_booking'));

    expect($registration->refresh()->payment_status)->toBe(PaymentStatus::Paid);
});

it('ignores a product-kind OrderLineReversed', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    // The product line's refId deliberately points at this registration, to
    // prove the guard is on `kind` and not on whether refId resolves.
    event(reversalOrderLineReversedEvent($registration, kind: 'product'));

    expect($registration->refresh()->payment_status)->toBe(PaymentStatus::Paid);
});

it('replays a reversal idempotently', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    Event::fake([RegistrationPaymentStatusChanged::class]);

    event(reversalPaymentReversedEvent($registration));
    event(reversalPaymentReversedEvent($registration));

    Event::assertDispatchedTimes(RegistrationPaymentStatusChanged::class, 1);
});

it('cancels the registration when the deadline passes and the order is cancelled as reversal_unpaid', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    event(reversalPaymentReversedEvent($registration));

    Event::fake([RegistrationCancelled::class]);

    event(reversalOrderCancelledEvent($registration));

    $registration->refresh();

    expect($registration->status)->toBe(RegistrationStatus::Cancelled)
        ->and($registration->reason_code)->toBe('payment_reversed')
        ->and($registration->cancelled_by_user_id)->toBeNull()
        // The quota goes back to the pool, so the waitlist can promote.
        ->and($quota->fresh()->consumed)->toBe(0);

    Event::assertDispatched(
        RegistrationCancelled::class,
        fn (RegistrationCancelled $event): bool => $event->registrationId === $registration->id
            && $event->reasonCode === 'payment_reversed'
            && $event->cancelledByUserId === null,
    );
});

it('leaves the registration alone on an OrderCancelled with any other reason code', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    event(reversalOrderCancelledEvent($registration, reasonCode: 'operator'));

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Confirmed)
        ->and($quota->fresh()->consumed)->toBe(1);
});

it('ignores an OrderCancelled that settles something other than a registration', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    event(reversalOrderCancelledEvent($registration, settlesType: 'resource_booking'));

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Confirmed);
});

it('leaves a cart order\'s registrations to the per-line event rather than the order-level one', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    // The same partition `OrderPaid`/`OrderLineSettled` already draws: a cart
    // order's own event cannot say which of its lines to cancel, and its
    // `settlesId` is the cart's, not this registration's.
    event(reversalOrderCancelledEvent($registration, settlesType: 'cart'));

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Confirmed)
        ->and($quota->fresh()->consumed)->toBe(1);
});

it('cancels a cart line\'s registration when the cart order is cancelled as reversal_unpaid', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    Event::fake([RegistrationCancelled::class]);

    event(reversalOrderLineCancelledEvent($registration));

    $registration->refresh();

    expect($registration->status)->toBe(RegistrationStatus::Cancelled)
        ->and($registration->reason_code)->toBe('payment_reversed')
        ->and($registration->cancelled_by_user_id)->toBeNull()
        ->and($quota->fresh()->consumed)->toBe(0);

    Event::assertDispatched(
        RegistrationCancelled::class,
        fn (RegistrationCancelled $event): bool => $event->registrationId === $registration->id
            && $event->reasonCode === 'payment_reversed',
    );
});

/**
 * Whole-branch review finding 3: a whole-order refund released the seat for
 * a direct registration order and silently did not for a cart order, which
 * is the same money going back with the place, the quota and the admission
 * left standing. `RecordRefund` now emits the per-line event the cart
 * cancellation path already used, and this listener recognises its reason.
 */
it('cancels a cart line\'s registration when the cart order is wholly refunded (#73)', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    Event::fake([RegistrationCancelled::class]);

    event(reversalOrderLineCancelledEvent($registration, reasonCode: 'order_refunded'));

    $registration->refresh();

    // The same reason code a direct order's refund writes, so the audit
    // column does not depend on which kind of order paid for the place.
    expect($registration->status)->toBe(RegistrationStatus::Cancelled)
        ->and($registration->reason_code)->toBe('order_refunded')
        ->and($quota->fresh()->consumed)->toBe(0);

    Event::assertDispatched(
        RegistrationCancelled::class,
        fn (RegistrationCancelled $event): bool => $event->registrationId === $registration->id
            && $event->reasonCode === 'order_refunded',
    );
});

it('ignores an OrderLineCancelled whose reason this module was not told to act on', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    event(reversalOrderLineCancelledEvent($registration, reasonCode: 'some_other_reason'));

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Confirmed)
        ->and($quota->fresh()->consumed)->toBe(1);
});

it('ignores a product-kind OrderLineCancelled', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    // The product line's refId deliberately points at this registration, to
    // prove the guard is on `kind` and not on whether refId resolves.
    event(reversalOrderLineCancelledEvent($registration, kind: 'product'));

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Confirmed)
        ->and($quota->fresh()->consumed)->toBe(1);
});

it('ignores an OrderLineCancelled with any other reason code', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    event(reversalOrderLineCancelledEvent($registration, reasonCode: 'operator'));

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Confirmed)
        ->and($quota->fresh()->consumed)->toBe(1);
});

it('does not cancel a cart line twice when the same OrderLineCancelled is replayed', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    $cancelled = reversalOrderLineCancelledEvent($registration);

    event($cancelled);
    event($cancelled);

    // A second release would push the quota below zero, or throw.
    expect($registration->refresh()->status)->toBe(RegistrationStatus::Cancelled)
        ->and($quota->fresh()->consumed)->toBe(0);
});

it('cancels the registration on a whole-order refund', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    Event::fake([RegistrationCancelled::class]);

    event(reversalOrderRefundedEvent($registration, whole: true));

    $registration->refresh();

    expect($registration->status)->toBe(RegistrationStatus::Cancelled)
        ->and($registration->reason_code)->toBe('order_refunded')
        ->and($quota->fresh()->consumed)->toBe(0);

    Event::assertDispatched(RegistrationCancelled::class);
});

it('leaves the registration alone on a partial refund', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    event(reversalOrderRefundedEvent($registration, whole: false));

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Confirmed)
        ->and($quota->fresh()->consumed)->toBe(1);
});

it('does not cancel twice when the same OrderCancelled is replayed', function (): void {
    reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    $cancelled = reversalOrderCancelledEvent($registration);

    event($cancelled);
    event($cancelled);

    // A second release would push the quota below zero, or throw.
    expect($registration->refresh()->status)->toBe(RegistrationStatus::Cancelled)
        ->and($quota->fresh()->consumed)->toBe(0);
});
