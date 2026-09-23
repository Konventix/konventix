<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Commerce\Actions\AddProductToCart;
use Konventix\Commerce\Actions\ChangeCartLineQuantity;
use Konventix\Commerce\Actions\ExpireCarts;
use Konventix\Commerce\Actions\OpenCart;
use Konventix\Commerce\Actions\PlaceRegistrationLineInCart;
use Konventix\Commerce\Actions\RemoveCartLine;
use Konventix\Commerce\Actions\ResolveRemovedCartLines;
use Konventix\Commerce\Actions\RestoreCartLine;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Enums\HostedPaymentState;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\CartExpired;
use Konventix\Commerce\Events\CartLineExpired;
use Konventix\Commerce\Events\CartLineRemoved;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\StockExhausted;
use Konventix\Commerce\Exceptions\StockExhaustedException;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\CheckoutRedirect;
use Konventix\Commerce\Settlement\ProvidesHostedCheckout;
use Konventix\Commerce\Settlement\SettlementAdapter;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;

/**
 * The cart and its reservation (ADR-0047, Task 9): a person-owned root that
 * locks stock as a quota for `Cart::LIFETIME_MINUTES`, and the sweep that
 * releases it again when nobody checks out.
 */
function cartVariant(int $capacity = 10): ProductVariant
{
    $product = Product::factory()->create();

    return ProductVariant::factory()->for($product, 'product')->withCapacity($capacity)->create();
}

it('consumes stock on add and merges a second add of the same variant into one line', function (): void {
    $this->withBrand();
    $variant = cartVariant(10);
    $cart = Cart::factory()->create();

    $first = app(AddProductToCart::class)->handle($cart, $variant, 3);
    $second = app(AddProductToCart::class)->handle($cart, $variant, 2);

    expect($variant->refresh()->stock_consumed)->toBe(5)
        ->and($second->getKey())->toBe($first->getKey())
        ->and($second->quantity)->toBe(5)
        ->and(CartLine::query()->where('cart_id', $cart->getKey())->count())->toBe(1);
});

it('refuses an add beyond remaining stock and consumes nothing, leaving an earlier add intact', function (): void {
    Event::fake([StockExhausted::class]);
    $this->withBrand();
    $variant = cartVariant(5);
    $cart = Cart::factory()->create();

    app(AddProductToCart::class)->handle($cart, $variant, 4);

    expect(fn () => app(AddProductToCart::class)->handle($cart, $variant, 2))
        ->toThrow(StockExhaustedException::class);

    expect($variant->refresh()->stock_consumed)->toBe(4)
        ->and(CartLine::query()->where('cart_id', $cart->getKey())->sole()->quantity)->toBe(4);
});

it('emits StockExhausted the moment an add exhausts the variant', function (): void {
    Event::fake([StockExhausted::class]);
    $this->withBrand();
    $variant = cartVariant(3);
    $cart = Cart::factory()->create();

    app(AddProductToCart::class)->handle($cart, $variant, 3);

    Event::assertDispatched(StockExhausted::class, fn (StockExhausted $event): bool => $event->variantId === $variant->getKey());
});

it('releases a product line\'s stock on removal', function (): void {
    Event::fake([CartLineRemoved::class]);
    $this->withBrand();
    $variant = cartVariant(10);
    $cart = Cart::factory()->create();
    $line = app(AddProductToCart::class)->handle($cart, $variant, 4);

    app(RemoveCartLine::class)->handle($line);

    expect($variant->refresh()->stock_consumed)->toBe(0)
        ->and(CartLine::query()->whereKey($line->getKey())->exists())->toBeFalse();
    Event::assertNotDispatched(CartLineRemoved::class);
});

it('soft-removes a registration line and emits nothing yet — #219\'s undo window', function (): void {
    // The old behaviour emitted `CartLineRemoved` from this very call, and
    // Registration cancelled the registration behind the line in the same
    // click. Nothing irreversible may happen while the buyer can still take
    // it back, so the click writes one column and says nothing.
    Event::fake([CartLineRemoved::class]);
    $this->withBrand();
    $cart = Cart::factory()->create();
    $line = CartLine::factory()->forCart($cart)->registration()->create();

    app(RemoveCartLine::class)->handle($line);

    expect(CartLine::query()->whereKey($line->getKey())->exists())->toBeTrue()
        ->and($line->fresh()->removed_at)->not->toBeNull()
        ->and($cart->lines()->count())->toBe(0)
        ->and($cart->removedLines()->count())->toBe(1);
    Event::assertNotDispatched(CartLineRemoved::class);
});

it('puts a soft-removed registration line back, still without emitting anything', function (): void {
    Event::fake([CartLineRemoved::class]);
    $this->withBrand();
    $cart = Cart::factory()->create();
    $line = CartLine::factory()->forCart($cart)->registration()->create();

    app(RemoveCartLine::class)->handle($line);
    app(RestoreCartLine::class)->handle($line->fresh());

    expect($line->fresh()->removed_at)->toBeNull()
        ->and($cart->lines()->count())->toBe(1)
        ->and($cart->removedLines()->count())->toBe(0);
    Event::assertNotDispatched(CartLineRemoved::class);
});

it('emits CartLineRemoved with its requestKey once the undo window closes at checkout', function (): void {
    Event::fake([CartLineRemoved::class]);
    $brand = $this->withBrand();
    $cart = Cart::factory()->create();
    // request_key is a ulid() column — a fixed-width char(26) in Postgres —
    // so a real ULID-shaped value is used rather than a short literal,
    // which Postgres would space-pad and fail the equality check below.
    $requestKey = (string) Str::ulid();
    $line = CartLine::factory()->forCart($cart)->registration($requestKey)->create();

    app(RemoveCartLine::class)->handle($line);
    app(ResolveRemovedCartLines::class)->handle($cart->fresh());

    // Gone for good now — nothing may hold a registration on a cart whose
    // order may be paid and therefore never swept again.
    expect(CartLine::query()->whereKey($line->getKey())->exists())->toBeFalse();
    Event::assertDispatched(CartLineRemoved::class, fn (CartLineRemoved $event): bool => $event->cartId === $cart->getKey()
        && $event->brandId === $brand->getKey()
        && $event->kind === CartLineKind::Registration->value
        && $event->refId === $line->ref_id
        && $event->requestKey === $requestKey);
});

it('emits CartLineRemoved rather than CartLineExpired when the cart hold closes an unused undo window', function (): void {
    Event::fake([CartLineRemoved::class, CartLineExpired::class]);
    $this->withBrand();
    $cart = Cart::factory()->create();
    $kept = CartLine::factory()->forCart($cart)->registration()->create(['position' => 0]);
    $removed = CartLine::factory()->forCart($cart)->registration()->create(['position' => 1]);

    app(RemoveCartLine::class)->handle($removed);
    $cart->forceFill(['expires_at' => now()->subMinute()])->save();

    app(ExpireCarts::class)->handle();

    Event::assertDispatched(CartLineRemoved::class, fn (CartLineRemoved $event): bool => $event->refId === $removed->ref_id);
    Event::assertDispatched(CartLineExpired::class, fn (CartLineExpired $event): bool => $event->refId === $kept->ref_id);
    Event::assertDispatchedTimes(CartLineRemoved::class, 1);
    Event::assertDispatchedTimes(CartLineExpired::class, 1);
});

it('consumes the delta on a quantity increase and releases it on a decrease', function (): void {
    $this->withBrand();
    $variant = cartVariant(10);
    $cart = Cart::factory()->create();
    $line = app(AddProductToCart::class)->handle($cart, $variant, 3);

    app(ChangeCartLineQuantity::class)->handle($line, 6);
    expect($variant->refresh()->stock_consumed)->toBe(6);

    app(ChangeCartLineQuantity::class)->handle($line->refresh(), 2);
    expect($variant->refresh()->stock_consumed)->toBe(2)
        ->and($line->refresh()->quantity)->toBe(2);
});

it('refuses a quantity increase beyond remaining stock', function (): void {
    $this->withBrand();
    $variant = cartVariant(5);
    $cart = Cart::factory()->create();
    $line = app(AddProductToCart::class)->handle($cart, $variant, 3);

    expect(fn () => app(ChangeCartLineQuantity::class)->handle($line, 10))
        ->toThrow(StockExhaustedException::class);

    expect($variant->refresh()->stock_consumed)->toBe(3);
});

it('refuses a quantity change on a registration line', function (): void {
    $this->withBrand();
    $cart = Cart::factory()->create();
    $line = CartLine::factory()->forCart($cart)->registration()->create();

    expect(fn () => app(ChangeCartLineQuantity::class)->handle($line, 2))
        ->toThrow(LogicException::class);
});

it('carries the registration quantity onto the line at the per-unit amount, and still refuses to change it', function (): void {
    // Bugfix round 2026-09-21, Task 19: the buyer chose the number on the
    // registration form, so the line shows "3 × 50,00" — and the
    // registration holds exactly three quota units, which is why the cart
    // may not move the number afterwards.
    $brand = $this->withBrand();
    $registrationId = (string) Str::ulid();

    $line = app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: (string) Str::ulid(),
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: [['label' => 'Weekend Ticket', 'quantity' => 3, 'unit_amount_net' => '50.00', 'tax_class' => 'standard']],
    );

    expect($line->quantity)->toBe(3)
        ->and($line->unit_amount_net)->toBe('50.00');

    expect(fn () => app(ChangeCartLineQuantity::class)->handle($line, 2))
        ->toThrow(LogicException::class);

    expect($line->refresh()->quantity)->toBe(3);
});

it('falls back to one line at the summed total when the request lines disagree on quantity', function (): void {
    // An amendment's delta lines are priced independently, so there is no
    // single per-unit price to divide back out — the money is what has to
    // stay right.
    $brand = $this->withBrand();
    $registrationId = (string) Str::ulid();

    $line = app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: (string) Str::ulid(),
        registrationId: $registrationId,
        requestKey: (string) Str::ulid(),
        lines: [
            ['label' => 'Booth', 'quantity' => 1, 'unit_amount_net' => '250.00', 'tax_class' => 'standard'],
            ['label' => 'Crew badge', 'quantity' => 2, 'unit_amount_net' => '10.00', 'tax_class' => 'standard'],
        ],
    );

    expect($line->quantity)->toBe(1)
        ->and($line->unit_amount_net)->toBe('270.00');
});

it('returns the same cart on a second OpenCart::for() call', function (): void {
    $brand = $this->withBrand();
    $payerUserId = (string) Str::ulid();

    $first = OpenCart::for($brand->getKey(), $payerUserId);
    $second = OpenCart::for($brand->getKey(), $payerUserId);

    expect($second->getKey())->toBe($first->getKey())
        ->and(Cart::withoutBrandScope()->where('brand_id', $brand->getKey())->where('payer_user_id', $payerUserId)->count())->toBe(1);
});

it('moves expires_at forward on every change to the cart', function (): void {
    $this->withBrand();
    $variant = cartVariant(10);
    $cart = Cart::factory()->create();
    $originalExpiry = $cart->expires_at;

    $this->travel(1)->minutes();
    $line = app(AddProductToCart::class)->handle($cart, $variant, 2);
    expect($cart->refresh()->expires_at->gt($originalExpiry))->toBeTrue();
    $afterAdd = $cart->expires_at;

    $this->travel(1)->minutes();
    app(ChangeCartLineQuantity::class)->handle($line->refresh(), 4);
    expect($cart->refresh()->expires_at->gt($afterAdd))->toBeTrue();
    $afterChange = $cart->expires_at;

    $this->travel(1)->minutes();
    app(RemoveCartLine::class)->handle($line->refresh());
    expect($cart->refresh()->expires_at->gt($afterChange))->toBeTrue();
});

it('sweeps expired carts: releases product stock, emits CartLineExpired for a registration line, and leaves a fresh cart alone', function (): void {
    Event::fake([CartLineExpired::class, CartExpired::class]);
    $brand = $this->withBrand();
    $variant = cartVariant(10);

    // request_key is a ulid() column — a fixed-width char(26) in Postgres —
    // so a real ULID-shaped value is used rather than a short literal,
    // which Postgres would space-pad and fail the equality check below.
    $requestKey = (string) Str::ulid();

    $expiring = Cart::factory()->create();
    app(AddProductToCart::class)->handle($expiring, $variant, 4);
    $registrationLine = CartLine::factory()->forCart($expiring)->registration($requestKey)->create();
    // Adding touched expiry back into the future — push it into the past
    // again, after every write, so the sweep actually picks this cart up.
    $expiring->forceFill(['expires_at' => now()->subMinute()])->save();

    $fresh = Cart::factory()->create();

    $expiredCount = app(ExpireCarts::class)->handle();

    expect($expiredCount)->toBe(1)
        ->and($variant->refresh()->stock_consumed)->toBe(0)
        ->and($expiring->refresh()->status)->toBe(CartStatus::Expired)
        ->and($fresh->refresh()->status)->toBe(CartStatus::Open);

    Event::assertDispatched(CartLineExpired::class, fn (CartLineExpired $event): bool => $event->cartId === $expiring->getKey()
        && $event->kind === CartLineKind::Registration->value
        && $event->refId === $registrationLine->ref_id
        && $event->requestKey === $requestKey);

    Event::assertDispatched(CartExpired::class, fn (CartExpired $event): bool => $event->cartId === $expiring->getKey()
        && $event->brandId === $brand->getKey()
        && $event->payerUserId === $expiring->payer_user_id);
});

it('sweeps a checked-out cart past its grace whose order is still pending: cancels the order, releases stock, expires the cart and its registration line', function (): void {
    // Task 11 fix round 1: an abandoned hosted checkout must not hold
    // stock and a registration's spot forever.
    Event::fake([CartLineExpired::class, CartExpired::class]);
    $brand = $this->withBrand();
    $variant = cartVariant(10);

    $requestKey = (string) Str::ulid();
    $cart = Cart::factory()->create();
    app(AddProductToCart::class)->handle($cart, $variant, 4);
    $registrationLine = CartLine::factory()->forCart($cart)->registration($requestKey)->create();

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

    $expiredCount = app(ExpireCarts::class)->handle();

    expect($expiredCount)->toBe(1)
        ->and($variant->refresh()->stock_consumed)->toBe(0)
        ->and($cart->refresh()->status)->toBe(CartStatus::Expired)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);

    Event::assertDispatched(CartLineExpired::class, fn (CartLineExpired $event): bool => $event->cartId === $cart->getKey()
        && $event->kind === CartLineKind::Registration->value
        && $event->refId === $registrationLine->ref_id
        && $event->requestKey === $requestKey);

    Event::assertDispatched(CartExpired::class, fn (CartExpired $event): bool => $event->cartId === $cart->getKey()
        && $event->brandId === $brand->getKey());
});

it('leaves a cart whose hold was renewed between the query and the transaction (#231, finding 3)', function (): void {
    // `PlaceOrderFromCart` reuses a checked-out cart's still-pending order
    // and pushes `expires_at` a fresh grace forward on every new attempt,
    // and the moment a hold expires is exactly when a payer comes back to
    // the page. The candidate query re-checked only the order, and this
    // branch put a synchronous provider call into that window — so the payer
    // was cancelled mid-attempt and their money landed as unapplied.
    $this->withBrand();
    $variant = cartVariant(10);

    $cart = Cart::factory()->create();
    app(AddProductToCart::class)->handle($cart, $variant, 4);

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

    // The payer presses "pay again" after the sweep selected the cart and
    // before it opens its transaction.
    DB::listen(function (QueryExecuted $query) use ($cart): void {
        if (! str_contains($query->sql, 'from "carts"') || ! str_contains($query->sql, 'checked_out_order_id')) {
            return;
        }

        Cart::withoutBrandScope()->whereKey($cart->getKey())
            ->update(['expires_at' => now()->addMinutes(Cart::CHECKOUT_GRACE_MINUTES)]);
    });

    $expiredCount = app(ExpireCarts::class)->handle();

    expect($expiredCount)->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($cart->fresh()->status)->toBe(CartStatus::CheckedOut)
        // The stock the payer is about to pay for is still held.
        ->and($variant->refresh()->stock_consumed)->toBe(4);
});

it('leaves a checked-out cart whose order is already paid untouched, however long past its grace', function (): void {
    $this->withBrand();
    $variant = cartVariant(10);

    $cart = Cart::factory()->create();
    app(AddProductToCart::class)->handle($cart, $variant, 4);

    $order = Order::factory()->paid()->create([
        'settles_type' => 'cart',
        'settles_id' => $cart->id,
        'request_key' => $cart->id,
    ]);
    $cart->forceFill([
        'status' => CartStatus::CheckedOut,
        'checked_out_order_id' => $order->id,
        // Long past any grace window — still must never be touched.
        'expires_at' => now()->subDay(),
    ])->save();

    $expiredCount = app(ExpireCarts::class)->handle();

    expect($expiredCount)->toBe(0)
        ->and($variant->refresh()->stock_consumed)->toBe(4)
        ->and($cart->refresh()->status)->toBe(CartStatus::CheckedOut)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('leaves a checked-out cart still inside its grace window untouched', function (): void {
    $this->withBrand();
    $variant = cartVariant(10);

    $cart = Cart::factory()->create();
    app(AddProductToCart::class)->handle($cart, $variant, 4);

    $order = Order::factory()->create([
        'settles_type' => 'cart',
        'settles_id' => $cart->id,
        'request_key' => $cart->id,
        'status' => OrderStatus::Pending,
    ]);
    $cart->forceFill([
        'status' => CartStatus::CheckedOut,
        'checked_out_order_id' => $order->id,
        'expires_at' => now()->addMinutes(30),
    ])->save();

    $expiredCount = app(ExpireCarts::class)->handle();

    expect($expiredCount)->toBe(0)
        ->and($variant->refresh()->stock_consumed)->toBe(4)
        ->and($cart->refresh()->status)->toBe(CartStatus::CheckedOut)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending);
});

/**
 * A settlement adapter whose PSP answer the test sets (#231). The registry
 * resolves a fresh instance per call, so the answer and the call count are
 * static.
 */
final class CartSweepTestAdapter implements ProvidesHostedCheckout, SettlementAdapter
{
    public static HostedPaymentState $state = HostedPaymentState::NotPaid;

    public static int $asked = 0;

    public function markOrderPaid(string $accountId, Order $order, string $amountPaid, string $currency, string $settlementMethod): Order
    {
        return $order;
    }

    public function refund(string $accountId, Order $order, string $amount, string $currency): ?string
    {
        return null;
    }

    public function startCheckout(SettlementAccount $account, Order $order, string $returnUrl): CheckoutRedirect
    {
        return new CheckoutRedirect(paymentId: 'unused', url: 'https://checkout.test/unused');
    }

    public function hostedPaymentState(Payment $payment): HostedPaymentState
    {
        self::$asked++;

        return self::$state;
    }

    public function defaultCheckoutLabel(): string
    {
        return 'Test checkout';
    }
}

/**
 * A checked-out cart past its grace whose order still has an open hosted
 * payment — the population #231 is about.
 *
 * @return array{0: Cart, 1: Order, 2: Payment}
 */
function cartSweepTestOpenHostedCheckout(HostedPaymentState $state, string $sessionId = 'cs_sweep_1'): array
{
    test()->withBrand();

    CartSweepTestAdapter::$state = $state;
    CartSweepTestAdapter::$asked = 0;
    app(SettlementAdapterRegistry::class)->register(SettlementAccount::PROVIDER_STRIPE, CartSweepTestAdapter::class);

    $cart = Cart::factory()->create();

    $order = Order::factory()->create([
        'settles_type' => 'cart',
        'settles_id' => $cart->id,
        'request_key' => $cart->id,
        'status' => OrderStatus::Pending,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'external_id' => $sessionId,
        'amount' => '119.00',
        'currency' => 'EUR',
    ]);

    $cart->forceFill([
        'status' => CartStatus::CheckedOut,
        'checked_out_order_id' => $order->id,
        'expires_at' => now()->subMinute(),
    ])->save();

    return [$cart, $order, $payment];
}

/**
 * A shop operator of `$brandId` — the recipient of the sweep's operations
 * notice (#231's fourth criterion). Same shape as the reconciliation test's
 * own holder.
 */
function cartSweepTestOperator(string $brandId): User
{
    PermissionModel::query()->firstOrCreate(['name' => 'commerce.manage-products', 'guard_name' => 'web']);

    $role = Role::query()->create(['name' => 'Sweep Manager', 'guard_name' => 'web', 'brand_id' => $brandId]);
    $role->givePermissionTo(['commerce.manage-products']);

    $user = User::factory()->create();

    event(new UserRegistered(
        userId: $user->id,
        brandId: $brandId,
        email: $user->email,
        displayName: $user->name,
        username: $user->username,
        locale: $user->locale ?? 'en',
        registeredAt: now()->toIso8601String(),
    ));

    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
        'brand_id' => $brandId,
    ]);

    return $user;
}

it('books a checked-out cart\'s order instead of cancelling it when the PSP says the session was paid', function (): void {
    // The DEV incident of 2026-09-08: a Stripe session paid at 01:57 whose
    // webhook never arrived, cancelled by this sweep at 03:00:05 with the
    // money still at Stripe (#231).
    [$cart, $order, $payment] = cartSweepTestOpenHostedCheckout(HostedPaymentState::Paid);

    Event::fake([OrderPaid::class]);

    $expiredCount = app(ExpireCarts::class)->handle();

    expect($expiredCount)->toBe(0)
        ->and(CartSweepTestAdapter::$asked)->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Succeeded)
        // The cart is not expired either: its lines paid for something.
        ->and($cart->fresh()->status)->toBe(CartStatus::CheckedOut);

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $event): bool => $event->orderId === $order->id);
});

it('leaves the order standing when the PSP cannot say whether the session was paid', function (): void {
    // Not knowing must never cancel an order somebody may have paid for;
    // the next run asks again.
    [$cart, $order] = cartSweepTestOpenHostedCheckout(HostedPaymentState::Unreachable);

    $expiredCount = app(ExpireCarts::class)->handle();

    expect($expiredCount)->toBe(0)
        ->and(CartSweepTestAdapter::$asked)->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($cart->fresh()->status)->toBe(CartStatus::CheckedOut);
});

it('stops asking once the run has spent its probe budget (#231)', function (): void {
    // An outage cancels nothing, so the candidate set only grows and the next
    // run would make more sequential provider calls than the last. Past the
    // budget the run simply stops asking; those orders keep their seats for
    // another quarter of an hour (fix round 1, review finding 4).
    config(['commerce.sweeps.probe_budget' => 1]);

    [, $first] = cartSweepTestOpenHostedCheckout(HostedPaymentState::Unreachable, 'cs_sweep_budget_1');
    [, $second] = cartSweepTestOpenHostedCheckout(HostedPaymentState::Unreachable, 'cs_sweep_budget_2');

    CartSweepTestAdapter::$asked = 0;

    $expiredCount = app(ExpireCarts::class)->handle();

    expect($expiredCount)->toBe(0)
        ->and(CartSweepTestAdapter::$asked)->toBe(1)
        // Not asked is not "not paid": both orders stand.
        ->and($first->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($second->fresh()->status)->toBe(OrderStatus::Pending);
});

it('keeps sweeping when the probe budget is configured to zero (#231)', function (): void {
    // A zero is a plausible reading of "turn probing off", and any
    // non-numeric env value casts to one. It used to leave every order
    // unasked — a state that reaches no release valve — so both sweeps
    // stopped cancelling anything at all, silently and permanently (review
    // NEW-1). A zero now means the default.
    config([
        'commerce.sweeps.probe_budget' => 0,
        'commerce.sweeps.unknown_payment_grace_hours' => 24,
    ]);

    [$cart, $order] = cartSweepTestOpenHostedCheckout(HostedPaymentState::NotPaid);
    CartSweepTestAdapter::$asked = 0;

    expect(app(ExpireCarts::class)->handle())->toBe(1)
        ->and(CartSweepTestAdapter::$asked)->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($cart->fresh()->status)->toBe(CartStatus::Expired);
});

it('cancels an order the PSP has never been able to answer for once the hold runs out (#231)', function (): void {
    // The release valve: a provider that stays silent must not hold a seat,
    // a quota and stock for the length of an outage. Money that did move
    // comes back as unapplied money the console shows and can refund (#6),
    // and the operators are told this happened.
    config(['commerce.sweeps.unknown_payment_grace_hours' => 24]);

    [$cart, $order] = cartSweepTestOpenHostedCheckout(HostedPaymentState::Unreachable);
    $holder = cartSweepTestOperator($order->brand_id);

    // The clock starts where the payer's window ended (F-1), not at the
    // order's creation.
    $cart->forceFill(['expires_at' => now()->subDays(2)])->save();

    Queue::fake();

    $expiredCount = app(ExpireCarts::class)->handle();

    expect($expiredCount)->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($cart->fresh()->status)->toBe(CartStatus::Expired);

    // #231's fourth criterion: the operator hears about an order called off
    // without the provider ever saying what happened to its money.
    Queue::assertPushedOn('messaging', SendQueuedMessage::class, function (SendQueuedMessage $job) use ($holder): bool {
        $payload = $job->message->payload->toArray();

        return $job->message->recipientId === $holder->id
            && $job->message->templateKey === 'commerce.operations-notice'
            && $payload['kind'] === 'sweep-cancelled-unsettled';
    });
});

it('holds an order the PSP says is still travelling far past the unreachable grace (#231)', function (): void {
    // SEPA direct debit and bank transfer take banking days. Cancelling one
    // a day in would mail the buyer mid-transfer — the exact failure the PSP
    // question was added to prevent.
    config([
        'commerce.sweeps.unknown_payment_grace_hours' => 24,
        'commerce.sweeps.in_flight_grace_days' => 14,
    ]);

    [$cart, $order] = cartSweepTestOpenHostedCheckout(HostedPaymentState::InFlight);

    $cart->forceFill(['expires_at' => now()->subDays(3)])->save();

    expect(app(ExpireCarts::class)->handle())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($cart->fresh()->status)->toBe(CartStatus::CheckedOut);

    // Only its own, much longer horizon ends it.
    $cart->forceFill(['expires_at' => now()->subDays(15)])->save();

    expect(app(ExpireCarts::class)->handle())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('never cancels an order this run did not ask the PSP about (#231)', function (): void {
    // The budget manufactures no verdict: an order past the run's last probe
    // is not an order the provider failed to answer for, and cancelling it
    // would release a seat with no question asked at all — during an outage,
    // which is exactly when the budget runs out.
    config([
        'commerce.sweeps.probe_budget' => 1,
        'commerce.sweeps.unknown_payment_grace_hours' => 24,
    ]);

    // The first candidate spends the run's only question…
    [, $probed] = cartSweepTestOpenHostedCheckout(HostedPaymentState::NotPaid, 'cs_sweep_probed');

    // …so this one, old enough for the valve, is never asked about.
    [$cart, $order] = cartSweepTestOpenHostedCheckout(HostedPaymentState::NotPaid, 'cs_sweep_unprobed');
    $cart->forceFill(['expires_at' => now()->subDays(5)])->save();

    CartSweepTestAdapter::$asked = 0;

    $expiredCount = app(ExpireCarts::class)->handle();

    expect(CartSweepTestAdapter::$asked)->toBe(1)
        ->and($probed->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($expiredCount)->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($cart->fresh()->status)->toBe(CartStatus::CheckedOut);
});

it('starts the release valve at the end of the hold, so renewing it pushes the valve (#231)', function (): void {
    // `PlaceOrderFromCart` reuses a checked-out cart's still-pending order
    // and moves `carts.expires_at` forward every time the payer starts
    // another attempt. Clocked on the order's age, the valve cancelled a
    // transfer that had left the bank that morning because an earlier
    // attempt on the same order was a fortnight old (fix round 3, review
    // F-1).
    config([
        'commerce.sweeps.unknown_payment_grace_hours' => 24,
        'commerce.sweeps.in_flight_grace_days' => 14,
    ]);

    [$cart, $order] = cartSweepTestOpenHostedCheckout(HostedPaymentState::InFlight);

    // An old order — the first attempt was three weeks ago.
    $order->forceFill(['created_at' => now()->subDays(21)])->save();

    // …whose payer started again just now, which renews the hold.
    $cart->forceFill(['expires_at' => now()->addMinutes(30)])->save();

    expect(app(ExpireCarts::class)->handle())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending);

    // The same order, once *that* attempt's window is a fortnight past.
    $cart->forceFill(['expires_at' => now()->subDays(15)])->save();

    expect(app(ExpireCarts::class)->handle())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('cancels the order once the PSP says nothing was paid', function (): void {
    [$cart, $order] = cartSweepTestOpenHostedCheckout(HostedPaymentState::NotPaid);

    $expiredCount = app(ExpireCarts::class)->handle();

    expect($expiredCount)->toBe(1)
        ->and(CartSweepTestAdapter::$asked)->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($cart->fresh()->status)->toBe(CartStatus::Expired);
});

it('runs the sweep through artisan commerce:expire-carts', function (): void {
    $this->withBrand();
    $cart = Cart::factory()->pastDue()->create();

    $this->artisan('commerce:expire-carts')
        ->expectsOutputToContain('Expired 1 cart(s).')
        ->assertSuccessful();

    expect($cart->refresh()->status)->toBe(CartStatus::Expired);
});
