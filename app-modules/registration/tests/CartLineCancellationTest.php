<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Commerce\Actions\ExpireCarts;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\CartLineExpired;
use Konventix\Commerce\Events\CartLineRemoved;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Order;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * ADR-0047 point 3 (Task 10): a cart line that dies — expired past its
 * reservation, or removed by the person shopping — tells Registration to
 * cancel the still-pending registration it was holding, releasing the
 * quota it consumed. The same shape the waitlist sweep already applies to
 * an unclaimed offer.
 *
 * The instant type used throughout requests settlement at submission
 * (`confirmation_gate: on_payment`) — with the shop feature off that goes
 * straight through Commerce's own `PlaceOrderFromSettlementRequest`, which
 * needs a seller identity, so every test attaches an operator up front
 * even the ones that only care about the cart-line-gone side.
 */
function cartCancelOperatorBrand(Brand $brand): Brand
{
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

function cartCancelInstantType(): RegistrationType
{
    return RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnPayment)
        ->priced(unitAmountNet: '30.00')
        ->create();
}

function cartCancelSubmit(RegistrationType $type, ?string $registrantUserId = null): Registration
{
    return app(SubmitRegistration::class)->handle(
        $type,
        $registrantUserId ?? (string) Str::ulid(),
        new SubmitRegistrationData(organizationName: null, billingRecipientName: 'Ada Lovelace', billingEmail: 'ada@example.test'),
    );
}

function cartCancelExpired(Registration $registration): void
{
    event(new CartLineExpired(
        cartId: (string) Str::ulid(),
        brandId: $registration->brand_id,
        kind: 'registration',
        refId: $registration->id,
        requestKey: $registration->id,
        expiredAt: now()->toIso8601String(),
    ));
}

function cartCancelRemoved(Registration $registration): void
{
    event(new CartLineRemoved(
        cartId: (string) Str::ulid(),
        brandId: $registration->brand_id,
        kind: 'registration',
        refId: $registration->id,
        requestKey: $registration->id,
        removedAt: now()->toIso8601String(),
    ));
}

it('cancels a pending registration and releases its quota when its cart line expires', function (): void {
    cartCancelOperatorBrand($this->withBrand());
    $quota = Quota::factory()->withCapacity(5)->create();
    $type = cartCancelInstantType();
    $type->quotas()->attach($quota);

    $registration = cartCancelSubmit($type);

    expect($registration->status)->toBe(RegistrationStatus::Submitted)
        ->and($registration->payment_status)->toBe(PaymentStatus::Pending)
        ->and($quota->fresh()->consumed)->toBe(1);

    cartCancelExpired($registration);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Cancelled)
        ->and($registration->fresh()->reason_code)->toBe('cart_expired')
        ->and($quota->fresh()->consumed)->toBe(0);
});

it('cancels a pending registration when its cart line is removed instead of expiring', function (): void {
    cartCancelOperatorBrand($this->withBrand());
    $quota = Quota::factory()->withCapacity(5)->create();
    $type = cartCancelInstantType();
    $type->quotas()->attach($quota);

    $registration = cartCancelSubmit($type);

    cartCancelRemoved($registration);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Cancelled)
        ->and($quota->fresh()->consumed)->toBe(0);
});

it('leaves a paid registration untouched when its cart line expires', function (): void {
    cartCancelOperatorBrand($this->withBrand());
    $quota = Quota::factory()->withCapacity(5)->create();
    $type = cartCancelInstantType();
    $type->quotas()->attach($quota);

    $registration = cartCancelSubmit($type);
    $registration->forceFill(['payment_status' => PaymentStatus::Paid])->save();

    cartCancelExpired($registration);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Submitted)
        ->and($registration->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($quota->fresh()->consumed)->toBe(1);
});

it('is a no-op on a second cart-line-gone event for the same registration', function (): void {
    cartCancelOperatorBrand($this->withBrand());
    $quota = Quota::factory()->withCapacity(5)->create();
    $type = cartCancelInstantType();
    $type->quotas()->attach($quota);

    $registration = cartCancelSubmit($type);

    cartCancelExpired($registration);
    expect($registration->fresh()->status)->toBe(RegistrationStatus::Cancelled)
        ->and($quota->fresh()->consumed)->toBe(0);

    cartCancelExpired($registration);
    expect($registration->fresh()->status)->toBe(RegistrationStatus::Cancelled)
        ->and($quota->fresh()->consumed)->toBe(0);
});

it('cancels a pending registration when Commerce\'s checkout-grace sweep cancels its cart order', function (): void {
    // Task 11 fix round 1: an abandoned hosted checkout must not hold a
    // registration's spot forever. `ExpireCarts`' second pass emits the
    // same `CartLineExpired` the shopping pass already does, so this only
    // needs to prove the real sweep — not a hand-built event — still
    // reaches this module's listener end to end.
    cartCancelOperatorBrand($this->withBrand());
    $quota = Quota::factory()->withCapacity(5)->create();
    $type = cartCancelInstantType();
    $type->quotas()->attach($quota);

    $registration = cartCancelSubmit($type);
    expect($registration->status)->toBe(RegistrationStatus::Submitted);

    $cart = Cart::factory()->create();
    CartLine::factory()->forCart($cart)->registration($registration->id)->create([
        'ref_id' => $registration->id,
    ]);
    $order = Order::factory()->create([
        'settles_type' => 'cart',
        'settles_id' => $cart->id,
        'request_key' => $cart->id,
        'status' => OrderStatus::Pending,
    ]);
    $cart->forceFill([
        'status' => CartStatus::CheckedOut,
        'checked_out_order_id' => $order->id,
        'expires_at' => now()->subMinute(),
    ])->save();

    app(ExpireCarts::class)->handle();

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Cancelled)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($quota->fresh()->consumed)->toBe(0);
});

it('redirects to the cart when the shop feature is on and the type settles instantly', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $type = cartCancelInstantType();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$type->event_id}/register/{$type->id}", [
            'organizationName' => null,
            'billingRecipientName' => 'Ada Lovelace',
            'billingEmail' => 'ada@example.test',
        ])
        ->assertRedirect('/cart');
});
