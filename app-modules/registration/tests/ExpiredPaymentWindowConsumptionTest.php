<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * GitLab #131, the Registration half: a direct `instant` registration order
 * on a brand without the shop feature held its quota forever when nobody
 * paid. Commerce's `commerce:expire-registration-orders` sweep now cancels
 * such an order past the carts' own payment window and emits `OrderCancelled`
 * with reason `payment_window_expired`; this is the module that has to act on
 * it, or the sweep changes an order status and releases nothing.
 *
 * The seat is *held* while the payer is at the PSP — `SubmitRegistration`
 * consumes the quota the moment the registration exists (ADR-0047 point 3) —
 * so the window expiring is exactly the moment it has to go back.
 *
 * The listener is registered at boot
 * (`RegistrationServiceProvider::registerListeners()`), so these tests
 * dispatch the real Commerce event and exercise the real wiring. No Commerce
 * code beyond the event class is needed (ADR-0004).
 *
 * Helper names prefixed `expiredWindow*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function expiredWindowOperator(Brand $brand): Brand
{
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

/**
 * A submitted, unpaid registration holding one unit of `$quota` — the state
 * a direct `instant` order leaves behind while the payer is at the PSP.
 */
function expiredWindowPendingRegistration(Quota $quota): Registration
{
    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced()
        ->create();

    $type->quotas()->attach($quota);

    return app(SubmitRegistration::class)
        ->handle($type, (string) Str::ulid(), new SubmitRegistrationData(
            organizationName: null,
            billingRecipientName: null,
            billingEmail: null,
        ))
        ->refresh();
}

function expiredWindowOrderCancelledEvent(
    Registration $registration,
    string $reasonCode = 'payment_window_expired',
    string $settlesType = 'registration',
): OrderCancelled {
    return new OrderCancelled(
        orderId: (string) Str::ulid(),
        brandId: $registration->brand_id,
        settlesType: $settlesType,
        settlesId: $registration->id,
        // Nobody acted: a deadline passed (#131).
        cancelledByUserId: null,
        reasonCode: $reasonCode,
        creditNoteId: null,
        cancelledAt: now()->toIso8601String(),
    );
}

it('cancels the registration and releases the quota when the payment window expires', function (): void {
    expiredWindowOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = expiredWindowPendingRegistration($quota);

    // The seat is held from submission, which is what the sweep exists to
    // give back.
    expect($quota->fresh()->consumed)->toBe(1);

    Event::fake([RegistrationCancelled::class]);

    event(expiredWindowOrderCancelledEvent($registration));

    $registration->refresh();

    expect($registration->status)->toBe(RegistrationStatus::Cancelled)
        ->and($registration->reason_code)->toBe('payment_window_expired')
        ->and($registration->cancelled_by_user_id)->toBeNull()
        // Back in the pool, so the waitlist can promote.
        ->and($quota->fresh()->consumed)->toBe(0);

    Event::assertDispatched(
        RegistrationCancelled::class,
        fn (RegistrationCancelled $event): bool => $event->registrationId === $registration->id
            && $event->reasonCode === 'payment_window_expired'
            && $event->cancelledByUserId === null,
    );
});

it('keeps the reason distinct from a reversal that was never repaid', function (): void {
    expiredWindowOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = expiredWindowPendingRegistration($quota);

    event(expiredWindowOrderCancelledEvent($registration));

    // The audit column has to say what actually happened: nobody ever paid
    // here, which is not a payer taking money back.
    expect($registration->refresh()->reason_code)->not->toBe('payment_reversed');
});

it('ignores an expired window on an order that settles something else', function (): void {
    expiredWindowOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = expiredWindowPendingRegistration($quota);

    event(expiredWindowOrderCancelledEvent($registration, settlesType: 'cart'));

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Submitted)
        ->and($quota->fresh()->consumed)->toBe(1);
});

it('does not release the quota twice when the same event is replayed', function (): void {
    expiredWindowOperator($this->withBrand());

    $quota = Quota::factory()->withCapacity(5)->create();
    $registration = expiredWindowPendingRegistration($quota);

    $cancelled = expiredWindowOrderCancelledEvent($registration);

    Event::fake([RegistrationCancelled::class]);

    event($cancelled);
    event($cancelled);

    Event::assertDispatchedTimes(RegistrationCancelled::class, 1);
    expect($quota->fresh()->consumed)->toBe(0);
});
