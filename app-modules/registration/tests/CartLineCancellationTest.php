<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Konventix\Commerce\Actions\ExpireCarts;
use Konventix\Commerce\Actions\RemoveCartLine;
use Konventix\Commerce\Actions\RestoreCartLine;
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
use Konventix\Registration\Enums\CartHoldEnded;
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

/**
 * A real cart line pointing at `$registration`, the shape
 * `PlaceRegistrationLineInCart` produces — built by factory here because
 * these tests are about what happens to the line afterwards, not about how
 * it got there (the settlement path is `SalesWindowTest`'s subject).
 */
function cartCancelLine(Registration $registration, ?Cart $cart = null): CartLine
{
    $cart ??= Cart::factory()->create();

    return CartLine::factory()->forCart($cart)->registration($registration->id)->create([
        'ref_id' => $registration->id,
    ]);
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

it('cancels a pending registration with the removal reason when its cart line is finally removed', function (): void {
    // The event itself still cancels — what #219 changed is *when* Commerce
    // emits it (once the undo window has closed, not on the click). #220 is
    // the other half: the reason must say removal, not expiry.
    cartCancelOperatorBrand($this->withBrand());
    $quota = Quota::factory()->withCapacity(5)->create();
    $type = cartCancelInstantType();
    $type->quotas()->attach($quota);

    $registration = cartCancelSubmit($type);

    cartCancelRemoved($registration);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Cancelled)
        ->and($registration->fresh()->reason_code)->toBe(CartHoldEnded::Removed->value)
        ->and($registration->fresh()->reason_code)->not->toBe(CartHoldEnded::Expired->value)
        ->and($quota->fresh()->consumed)->toBe(0);
});

it('does not cancel anything when the buyer removes a cart line — the undo window stands between (#219)', function (): void {
    // This is the test that used to pin the bug: one click on the trash
    // button cancelled the registration behind the line, released its quota
    // and mailed the buyer, three seconds after they had confirmed it and
    // with twenty minutes of cart hold left. Nothing irreversible may happen
    // inside the window now, which is what makes the undo honest.
    cartCancelOperatorBrand($this->withBrand());
    $quota = Quota::factory()->withCapacity(5)->create();
    $type = cartCancelInstantType();
    $type->quotas()->attach($quota);

    $registration = cartCancelSubmit($type);
    $cart = Cart::factory()->create();
    $line = cartCancelLine($registration, $cart);

    app(RemoveCartLine::class)->handle($line);

    expect($line->fresh()->removed_at)->not->toBeNull()
        // Gone from everything that reads the cart...
        ->and($cart->lines()->count())->toBe(0)
        // ...and still recallable.
        ->and($cart->removedLines()->pluck('id')->all())->toBe([$line->id])
        // Nothing behind it moved.
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::Submitted)
        ->and($registration->fresh()->reason_code)->toBeNull()
        ->and($quota->fresh()->consumed)->toBe(1);
});

it('puts the line back and leaves the registration untouched when the buyer undoes the removal', function (): void {
    cartCancelOperatorBrand($this->withBrand());
    $quota = Quota::factory()->withCapacity(5)->create();
    $type = cartCancelInstantType();
    $type->quotas()->attach($quota);

    $registration = cartCancelSubmit($type);
    $cart = Cart::factory()->create();
    $line = cartCancelLine($registration, $cart);

    app(RemoveCartLine::class)->handle($line);
    app(RestoreCartLine::class)->handle($line->fresh());

    expect($line->fresh()->removed_at)->toBeNull()
        ->and($cart->lines()->pluck('id')->all())->toBe([$line->id])
        ->and($cart->removedLines()->count())->toBe(0)
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::Submitted)
        ->and($quota->fresh()->consumed)->toBe(1);
});

it('cancels with the removal reason and releases the quota when an undo window closes unused', function (): void {
    // The window rides the cart's own `expires_at` and the sweep that
    // already owns that clock is what closes it — no second schedule. The
    // reason is still `cart_line_removed`, not `cart_expired`: the cart
    // expiring is what ended the window, but removing the line is what the
    // buyer did and what the mail has to say (#220).
    cartCancelOperatorBrand($this->withBrand());
    $quota = Quota::factory()->withCapacity(5)->create();
    $type = cartCancelInstantType();
    $type->quotas()->attach($quota);

    $registration = cartCancelSubmit($type);
    $cart = Cart::factory()->create();
    $line = cartCancelLine($registration, $cart);

    app(RemoveCartLine::class)->handle($line);
    $cart->forceFill(['expires_at' => now()->subMinute()])->save();

    app(ExpireCarts::class)->handle();

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Cancelled)
        ->and($registration->fresh()->reason_code)->toBe(CartHoldEnded::Removed->value)
        ->and($quota->fresh()->consumed)->toBe(0);
});

it('leaves a line that is still in the cart expiring under its own reason', function (): void {
    // The other branch of the same sweep, so the two cannot be confused: a
    // line nobody removed still dies as `cart_expired`.
    cartCancelOperatorBrand($this->withBrand());
    $quota = Quota::factory()->withCapacity(5)->create();
    $type = cartCancelInstantType();
    $type->quotas()->attach($quota);

    $registration = cartCancelSubmit($type);
    $cart = Cart::factory()->create();
    cartCancelLine($registration, $cart);
    $cart->forceFill(['expires_at' => now()->subMinute()])->save();

    app(ExpireCarts::class)->handle();

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Cancelled)
        ->and($registration->fresh()->reason_code)->toBe(CartHoldEnded::Expired->value)
        ->and($quota->fresh()->consumed)->toBe(0);
});

it('leaves a confirmed registration standing when its cart line expires, and tells the operator (#211)', function (): void {
    cartCancelOperatorBrand($this->withBrand());
    $quota = Quota::factory()->withCapacity(5)->create();
    $type = cartCancelInstantType();
    $type->quotas()->attach($quota);

    $registration = cartCancelSubmit($type);
    // ADR-0047 point 3's case: confirmed, with the immediate payment still
    // sitting in the cart.
    $registration->forceFill([
        'status' => RegistrationStatus::Confirmed,
        'confirmed_at' => now(),
    ])->save();

    Log::spy();

    cartCancelExpired($registration);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Confirmed)
        ->and($registration->fresh()->payment_status)->toBe(PaymentStatus::Pending)
        ->and($registration->fresh()->reason_code)->toBeNull()
        // The seat stays held — that is the whole point of leaving it
        // standing (ADR-0057 point 5).
        ->and($quota->fresh()->consumed)->toBe(1);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'left standing')
            && $context['registration_id'] === $registration->id
            && $context['trigger'] === CartHoldEnded::Expired->value);

    // The counter beside that line is `RegistrationMetricsTest`'s subject —
    // `Metrics` is final, so it is asserted off a real scrape there rather
    // than mocked here.
});

it('still cancels a submitted and an approved registration when its cart line expires', function (): void {
    // The guard is on `confirmed` only: everything short of it expires the
    // way it always did.
    cartCancelOperatorBrand($this->withBrand());
    $quota = Quota::factory()->withCapacity(5)->create();
    $type = cartCancelInstantType();
    $type->quotas()->attach($quota);

    $submitted = cartCancelSubmit($type);
    $approved = cartCancelSubmit($type);
    $approved->forceFill(['status' => RegistrationStatus::Approved, 'approved_at' => now()])->save();

    expect($quota->fresh()->consumed)->toBe(2);

    cartCancelExpired($submitted);
    cartCancelExpired($approved);

    expect($submitted->fresh()->status)->toBe(RegistrationStatus::Cancelled)
        ->and($approved->fresh()->status)->toBe(RegistrationStatus::Cancelled)
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

it('does not redirect to the cart when the type settles instantly but confirms on approval, not on payment', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $type = RegistrationType::factory()
        ->settlement(RegistrationSettlement::Instant)
        ->confirmationGate(ConfirmationGate::OnApproval)
        ->priced(unitAmountNet: '30.00')
        ->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/events/{$type->event_id}/register/{$type->id}", [
            'organizationName' => null,
            'billingRecipientName' => 'Ada Lovelace',
            'billingEmail' => 'ada@example.test',
        ])
        ->assertRedirect(route('registration.mine.show', ['registration' => Registration::sole()->id]));
});
