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
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Operators\OperatorContext;
use Konventix\Registration\Actions\JoinWaitlist;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Enums\WaitlistState;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationPaymentStatusChanged;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationAmendment;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Registration\Models\WaitlistEntry;

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

/**
 * #236: the console's refund runs the `order_refunded` consumer inside the
 * operator's own request, on the admin host — where no brand is resolved and
 * `BrandScope` hardens every brand-owned query to zero rows (ADR-0029). The
 * registration itself is found through `withoutBrandScope()`, but the
 * `registrationType` relation and its quotas re-apply the scope, so the
 * release read `null` and the whole refund died with the money already gone.
 * Every read on this path escapes per relation, exactly as the mail
 * listeners do (`.ai/rules/app-modules-src.md`).
 */
it('cancels a cart line\'s registration and releases its quota with no brand in context (#236)', function (): void {
    $brand = reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = reversalPaidRegistration($quota);

    // An amendment too, so `ReleasesQuotas::amendedUnits()` — the other half
    // of the trait — is exercised without a brand as well (fix round 1,
    // review nit 10): two admissions added, so three units are held and all
    // three have to come back.
    RegistrationAmendment::factory()->create(['registration_id' => $registration->id]);
    $quota->fresh()->consume(2);

    expect($quota->fresh()->consumed)->toBe(3);

    // The console request: an operator, no brand.
    app(BrandContext::class)->forget();
    app(OperatorContext::class)->set($brand->operator);

    event(reversalOrderLineCancelledEvent($registration, reasonCode: 'order_refunded'));

    $reloaded = Registration::withoutBrandScope()->whereKey($registration->id)->sole();

    expect($reloaded->status)->toBe(RegistrationStatus::Cancelled)
        ->and($reloaded->reason_code)->toBe('order_refunded')
        ->and(Quota::withoutBrandScope()->whereKey($quota->id)->sole()->consumed)->toBe(0);
});

/**
 * #236 fix round 1, review finding 2: releasing the quota is only half the
 * point of cancelling the registration — the seat has to be offered on. The
 * chain is `releaseQuotas()` -> `QuotaReleased` -> `PromoteOnQuotaReleased`
 * (synchronous) -> `PromoteFromWaitlist`, and that Action read its quotas
 * through the `quotas()` relation, which re-applies BrandScope on the
 * *related* model. On the admin host it therefore found no entry at all: an
 * operator's refund freed a seat and nobody was ever told.
 */
it('offers a seat freed by a console refund to the waitlist, with no brand in context (#236)', function (): void {
    $brand = reversalOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(1)->create();
    $registration = reversalPaidRegistration($quota);

    $type = RegistrationType::withoutBrandScope()
        ->whereKey($registration->registration_type_id)
        ->sole();

    $waiting = app(JoinWaitlist::class)->handle($type, (string) Str::ulid(), reversalSubmission());

    expect($waiting->state)->toBe(WaitlistState::Queued)
        ->and($quota->fresh()->consumed)->toBe(1);

    // The console request: an operator, no brand.
    app(BrandContext::class)->forget();
    app(OperatorContext::class)->set($brand->operator);

    event(reversalOrderLineCancelledEvent($registration, reasonCode: 'order_refunded'));

    // The seat went straight back out on offer, and the promotion took the
    // unit the cancellation had just released.
    expect(WaitlistEntry::withoutBrandScope()->whereKey($waiting->id)->sole()->state)
        ->toBe(WaitlistState::Offered)
        ->and(Quota::withoutBrandScope()->whereKey($quota->id)->sole()->consumed)->toBe(1);
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
