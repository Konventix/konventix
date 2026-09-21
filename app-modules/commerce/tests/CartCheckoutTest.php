<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Actions\AddProductToCart;
use Konventix\Commerce\Actions\OpenCart;
use Konventix\Commerce\Actions\PlaceOrderFromCart;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Actions\RemoveCartLine;
use Konventix\Commerce\Actions\SendInvoiceMail;
use Konventix\Commerce\Documents\InvoiceDocumentRenderer;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\CartCheckedOut;
use Konventix\Commerce\Events\CartLineRemoved;
use Konventix\Commerce\Events\OrderLineSettled;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Commerce\Jobs\GenerateInvoiceDocument;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\CheckoutLegalTexts;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * Checkout of a cart into one order (ADR-0047 point 4, Task 11): one
 * checkout settles a mix of products and registrations, each order line
 * carrying `settles_kind`/`settles_ref`, and `OrderLineSettled` tells the
 * owning module which of them were paid.
 *
 * Helper names prefixed `cartCheckout*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function cartCheckoutBrand(): Brand
{
    $brand = test()->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

function cartCheckoutVariant(int $capacity = 10): ProductVariant
{
    // Published: the store endpoint now refuses an unpublished product's
    // variant (fix round 1), and every other test in this file reaches
    // the variant through that same endpoint or the Action it wraps.
    $product = Product::factory()->published()->create(['unit_amount_net' => '10.00']);

    return ProductVariant::factory()->for($product, 'product')->withCapacity($capacity)->create();
}

/**
 * A cart holding one product line (quantity 2 at 10.00 net each) and one
 * registration line (50.00 net), for the payer given.
 */
function cartCheckoutMixedCart(Brand $brand, string $payerUserId, ?string $registrationId = null): Cart
{
    $variant = cartCheckoutVariant();
    $cart = OpenCart::for($brand->getKey(), $payerUserId);
    app(AddProductToCart::class)->handle($cart, $variant, 2);

    $registrationId ??= (string) Str::ulid();
    CartLine::factory()->forCart($cart)->registration($registrationId)->create([
        'ref_id' => $registrationId,
        'label' => 'Weekend Ticket',
        'unit_amount_net' => '50.00',
        'position' => 1,
    ]);

    return $cart->fresh();
}

it('checks out a mixed cart into one order with two lines carrying settles_kind/ref and correct gross totals', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $registrationId = (string) Str::ulid();
    $cart = cartCheckoutMixedCart($brand, $user->getKey(), $registrationId);
    $account = cartCheckoutAccount();

    $redirect = app(PlaceOrderFromCart::class)->handle($cart, $account, cartCheckoutReturnUrl(), cartCheckoutBillingParty($user));

    expect($redirect->url)->toBe('https://checkout.stripe.test/cart-session');

    $order = Order::query()->where('settles_type', 'cart')->where('settles_id', $cart->id)->sole();

    // 2 x 10.00 net product + 1 x 50.00 net registration = 70.00 net;
    // ADR-0030 domestic standard, no operator small-business election, no
    // billing party at all: 19% on the whole thing.
    expect($order->total_net)->toBe('70.00')
        ->and($order->total_tax)->toBe('13.30')
        ->and($order->total_gross)->toBe('83.30')
        ->and($order->request_key)->toBe($cart->id)
        ->and($order->payer_user_id)->toBe($user->getKey())
        ->and($order->status)->toBe(OrderStatus::Pending);

    $lines = OrderLine::query()->where('order_id', $order->id)->orderBy('position')->get();

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->settles_kind)->toBe('product')
        ->and($lines[0]->line_gross)->toBe('23.80')
        ->and($lines[1]->settles_kind)->toBe('registration')
        ->and($lines[1]->settles_ref)->toBe($registrationId)
        ->and($lines[1]->line_gross)->toBe('59.50');

    expect($cart->fresh()->status)->toBe(CartStatus::CheckedOut)
        ->and($cart->fresh()->checked_out_order_id)->toBe($order->id);

    // Task 11 fix round 1: checkout resets the cart's reservation to the
    // sixty-minute checkout-grace window, not the twenty-minute shopping
    // one — well past the shopping window, comfortably inside the grace.
    expect($cart->fresh()->expires_at->diffInMinutes(now(), true))->toBeLessThan(60)
        ->and($cart->fresh()->expires_at->isFuture())->toBeTrue();

    expect(Payment::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);
});

it('emits CartCheckedOut and OrderPlaced with settlesType cart', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $cart = cartCheckoutMixedCart($brand, $user->getKey());
    $account = cartCheckoutAccount();

    Event::fake([CartCheckedOut::class, OrderPlaced::class]);

    app(PlaceOrderFromCart::class)->handle($cart, $account, cartCheckoutReturnUrl(), cartCheckoutBillingParty($user));

    Event::assertDispatched(CartCheckedOut::class, fn (CartCheckedOut $e): bool => $e->cartId === $cart->id
        && $e->brandId === $brand->getKey()
        && $e->payerUserId === $user->getKey());

    Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $e): bool => $e->settlesType === 'cart'
        && $e->settlesId === $cart->id);
});

it('emits OrderPaid(settlesType cart) and one OrderLineSettled per line on the webhook-success path', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $registrationId = (string) Str::ulid();
    $cart = cartCheckoutMixedCart($brand, $user->getKey(), $registrationId);
    $account = cartCheckoutAccount();

    $redirect = app(PlaceOrderFromCart::class)->handle($cart, $account, cartCheckoutReturnUrl(), cartCheckoutBillingParty($user));
    $order = Order::query()->where('settles_type', 'cart')->where('settles_id', $cart->id)->sole();
    $payment = Payment::withoutBrandScope()->where('id', $redirect->paymentId)->sole();

    Event::fake([OrderPaid::class, OrderLineSettled::class]);

    app(RecordPaymentSuccess::class)->handle($payment);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e): bool => $e->orderId === $order->id
        && $e->settlesType === 'cart');

    Event::assertDispatched(OrderLineSettled::class, 2);
    Event::assertDispatched(OrderLineSettled::class, fn (OrderLineSettled $e): bool => $e->orderId === $order->id
        && $e->kind === 'product'
        && $e->orderRequestKey === $order->request_key);
    Event::assertDispatched(OrderLineSettled::class, fn (OrderLineSettled $e): bool => $e->orderId === $order->id
        && $e->kind === 'registration'
        && $e->refId === $registrationId);
});

/**
 * ADR-0048: a real checkout, followed by the provider's success path
 * (`RecordPaymentSuccess`, the same call the Stripe webhook controller
 * makes once it verifies the event) issues the Rechnung for the cart order
 * — the gap this task closes: before it, only an invoice-settled
 * registration order produced any document at all.
 */
it('issues a Rechnung with the cart\'s billing party once the checkout is paid, and stays at one on replay', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    Storage::fake('invoices');
    Mail::fake();
    $user = User::factory()->create();
    $cart = cartCheckoutMixedCart($brand, $user->getKey());
    $account = cartCheckoutAccount();

    $redirect = app(PlaceOrderFromCart::class)->handle($cart, $account, cartCheckoutReturnUrl(), cartCheckoutBillingParty($user));
    $order = Order::query()->where('settles_type', 'cart')->where('settles_id', $cart->id)->sole();
    $payment = Payment::withoutBrandScope()->where('id', $redirect->paymentId)->sole();

    app(RecordPaymentSuccess::class)->handle($payment);

    $invoice = Invoice::query()->where('order_id', $order->id)->sole();

    expect($invoice->paid_at)->not->toBeNull()
        ->and($invoice->paid_via)->toBe('Stripe')
        ->and($invoice->billing_party->recipientName)->toBe($user->name)
        ->and($invoice->billing_party->email)->toBe($user->email);

    (new GenerateInvoiceDocument($invoice->id, $brand->id))
        ->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    Mail::assertSentCount(1);

    // A replayed webhook notification for the same, already-succeeded
    // payment must not produce a second invoice.
    app(RecordPaymentSuccess::class)->handle($payment->fresh());

    expect(Invoice::query()->where('order_id', $order->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The 250 € billing-address threshold (#4, §33 UStDV vs §14 Abs. 4 UStG)
|--------------------------------------------------------------------------
|
| Below GermanVat::SMALL_AMOUNT_INVOICE_THRESHOLD_GROSS a cart checkout still
| gathers nothing beyond account identity (see the mixed-cart tests above,
| all comfortably under it). These exercise the two sides of the boundary
| PlaceOrderFromCart::guardBillingAddress() enforces, and both directions a
| cart can move across it before checkout — not just a value pinned at
| exactly 250.
|
*/

/**
 * One registration line at the given net amount — enough to drive the
 * order's computed gross to a specific figure without the mixed cart's
 * product line complicating the arithmetic.
 */
function cartCheckoutSingleLineCart(Brand $brand, string $payerUserId, string $unitAmountNet): Cart
{
    $cart = OpenCart::for($brand->getKey(), $payerUserId);
    CartLine::factory()->forCart($cart)->registration()->create([
        'ref_id' => (string) Str::ulid(),
        'label' => 'Season Pass',
        'unit_amount_net' => $unitAmountNet,
        'quantity' => 1,
        'position' => 1,
    ]);

    return $cart->fresh();
}

it('refuses to check out a cart whose gross exceeds 250 € with no billing address, naming the missing fields', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    // net 211.00 * 1.19 = 251.09 gross — one cent over the ceiling.
    $cart = cartCheckoutSingleLineCart($brand, $user->getKey(), '211.00');
    $account = cartCheckoutAccount();

    expect(fn () => app(PlaceOrderFromCart::class)->handle($cart, $account, cartCheckoutReturnUrl(), cartCheckoutBillingParty($user)))
        ->toThrow(ValidationException::class);

    expect(Order::query()->count())->toBe(0);
});

it('checks out a cart over 250 € once a billing address is given', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $cart = cartCheckoutSingleLineCart($brand, $user->getKey(), '211.00');
    $account = cartCheckoutAccount();
    $billingParty = cartCheckoutBillingParty($user, 'Hauptstraße 1', '10115', 'Berlin', 'DE');

    app(PlaceOrderFromCart::class)->handle($cart, $account, cartCheckoutReturnUrl(), $billingParty);

    $order = Order::query()->where('settles_type', 'cart')->where('settles_id', $cart->id)->sole();

    expect($order->total_gross)->toBe('251.09')
        ->and($order->billing_party->addressLine)->toBe('Hauptstraße 1')
        ->and($order->billing_party->postalCode)->toBe('10115')
        ->and($order->billing_party->city)->toBe('Berlin')
        ->and($order->billing_party->countryCode)->toBe('DE');
});

it('stays exempt from the address requirement at exactly 250 € gross', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    // net 210.08 * 1.19, rounded to cents = 250.00 gross exactly.
    $cart = cartCheckoutSingleLineCart($brand, $user->getKey(), '210.08');
    $account = cartCheckoutAccount();

    app(PlaceOrderFromCart::class)->handle($cart, $account, cartCheckoutReturnUrl(), cartCheckoutBillingParty($user));

    $order = Order::query()->where('settles_type', 'cart')->where('settles_id', $cart->id)->sole();
    expect($order->total_gross)->toBe('250.00');
});

it('demands a billing address once a second line pushes a cart past 250 €, even though the first line alone would not', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $cart = cartCheckoutSingleLineCart($brand, $user->getKey(), '150.00');
    CartLine::factory()->forCart($cart)->registration()->create([
        'ref_id' => (string) Str::ulid(),
        'label' => 'Add-on Pass',
        'unit_amount_net' => '100.00',
        'quantity' => 1,
        'position' => 2,
    ]);
    $account = cartCheckoutAccount();

    expect(fn () => app(PlaceOrderFromCart::class)->handle($cart->fresh(), $account, cartCheckoutReturnUrl(), cartCheckoutBillingParty($user)))
        ->toThrow(ValidationException::class);

    expect(Order::query()->count())->toBe(0);
});

it('drops the address requirement once a line is removed and the cart falls back under 250 €', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    Event::fake([CartLineRemoved::class]);
    $user = User::factory()->create();
    $cart = cartCheckoutSingleLineCart($brand, $user->getKey(), '150.00');
    $secondLine = CartLine::factory()->forCart($cart)->registration()->create([
        'ref_id' => (string) Str::ulid(),
        'label' => 'Add-on Pass',
        'unit_amount_net' => '100.00',
        'quantity' => 1,
        'position' => 2,
    ]);
    // Combined net 250.00 -> gross 297.50 demands an address; removing the
    // add-on brings the cart back to net 150.00 -> gross 178.50, well under.
    app(RemoveCartLine::class)->handle($secondLine);
    $account = cartCheckoutAccount();

    app(PlaceOrderFromCart::class)->handle($cart->fresh(), $account, cartCheckoutReturnUrl(), cartCheckoutBillingParty($user));

    $order = Order::query()->where('settles_type', 'cart')->where('settles_id', $cart->id)->sole();
    expect($order->total_gross)->toBe('178.50');
});

it('422s a cart checkout over 250 € with no billing address, and places no order', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    cartCheckoutSingleLineCart($brand, $user->getKey(), '211.00');
    $account = cartCheckoutAccount();

    test()->actingAs($user)
        ->post('/cart/checkout', ['account' => $account->id])
        ->assertRedirect()
        ->assertSessionHasErrors('billingAddressLine');

    expect(Order::query()->count())->toBe(0);
});

it('checks out over 250 € through the controller once the billing address fields are submitted', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    cartCheckoutSingleLineCart($brand, $user->getKey(), '211.00');
    $account = cartCheckoutAccount();

    test()->actingAs($user)
        ->post('/cart/checkout', [
            'account' => $account->id,
            'billingAddressLine' => 'Hauptstraße 1',
            'billingPostalCode' => '10115',
            'billingCity' => 'Berlin',
            'billingCountryCode' => 'DE',
        ])
        ->assertRedirect('https://checkout.stripe.test/cart-session');

    $order = Order::query()->where('settles_type', 'cart')->sole();
    expect($order->billing_party->addressLine)->toBe('Hauptstraße 1')
        ->and($order->billing_party->city)->toBe('Berlin');
});

/**
 * Bugfix round 2026-09-21, Task 3: the provider used to be handed the cart
 * page as the one return URL, and the cart is `checked_out` by the time the
 * payer comes back — so paying ended on "Your cart is empty". Every hosted
 * checkout returns to the order's own page now, success and cancel alike.
 */
it('hands the provider the placed order\'s own page as the return URL', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    cartCheckoutMixedCart($brand, $user->getKey());
    $account = cartCheckoutAccount();

    test()->actingAs($user)
        ->post('/cart/checkout', ['account' => $account->id])
        ->assertRedirect('https://checkout.stripe.test/cart-session');

    $order = Order::query()->where('settles_type', 'cart')->sole();

    /** @var CartCheckoutTestFakeStripe $stripe */
    $stripe = app(StripeApi::class);

    expect($stripe->sessions)->toHaveCount(1)
        ->and($stripe->sessions[0]['success_url'])->toStartWith('http')
        ->and($stripe->sessions[0]['success_url'])->toEndWith('/my/orders/'.$order->getKey())
        ->and($stripe->sessions[0]['cancel_url'])->toBe($stripe->sessions[0]['success_url']);

    // And that page renders for the payer who just came back — pending,
    // with the attempt still pending, which the page states as "waiting
    // for the confirmation" rather than as an empty cart.
    test()->actingAs($user)
        ->get('/my/orders/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('commerce/my/Show')
            ->where('order.id', $order->getKey())
            ->where('order.status', 'pending')
            ->where('order.paymentStatus', 'pending')
            ->where('order.payUrl', '/pay/order/'.$order->getKey()));
});

it('shows billingAddressRequired on the cart page once the total passes 250 €', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    cartCheckoutSingleLineCart($brand, $user->getKey(), '211.00');

    test()->actingAs($user)
        ->get('/cart')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cart.billingAddressRequired', true));
});

it('leaves billingAddressRequired false on the cart page under 250 €', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    cartCheckoutMixedCart($brand, $user->getKey());

    test()->actingAs($user)
        ->get('/cart')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cart.billingAddressRequired', false));
});

it('reuses the pending order on a second checkout of the same cart', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $cart = cartCheckoutMixedCart($brand, $user->getKey());
    $account = cartCheckoutAccount();

    app(PlaceOrderFromCart::class)->handle($cart, $account, cartCheckoutReturnUrl(), cartCheckoutBillingParty($user));
    $orderCountAfterFirst = Order::query()->where('settles_type', 'cart')->count();
    $expiresAfterFirst = $cart->fresh()->expires_at;

    // A little time passes before the payer retries — the reused branch
    // must extend the grace window again, the same way `touchExpiry()`
    // extends the shopping one on continued activity.
    test()->travel(5)->minutes();

    app(PlaceOrderFromCart::class)->handle($cart->fresh(), $account, cartCheckoutReturnUrl(), cartCheckoutBillingParty($user));

    expect(Order::query()->where('settles_type', 'cart')->count())->toBe($orderCountAfterFirst)
        ->and(Payment::withoutBrandScope()->count())->toBe(2)
        ->and($cart->fresh()->expires_at->gt($expiresAfterFirst))->toBeTrue();
});

it('refuses to check out an expired cart', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $cart = cartCheckoutMixedCart($brand, $user->getKey());
    $cart->forceFill(['status' => CartStatus::Expired])->save();
    $account = cartCheckoutAccount();

    expect(fn () => app(PlaceOrderFromCart::class)->handle($cart, $account, cartCheckoutReturnUrl(), cartCheckoutBillingParty($user)))
        ->toThrow(RuntimeException::class);

    expect(Order::query()->where('settles_type', 'cart')->count())->toBe(0);
});

it('refuses to check out an empty cart', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $cart = Cart::factory()->forPayer($user->getKey())->create();
    $account = cartCheckoutAccount();

    expect(fn () => app(PlaceOrderFromCart::class)->handle($cart, $account, cartCheckoutReturnUrl(), cartCheckoutBillingParty($user)))
        ->toThrow(RuntimeException::class);

    expect(Order::query()->where('settles_type', 'cart')->count())->toBe(0);
});

it('refuses a non-offered account and creates no order', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $cart = cartCheckoutMixedCart($brand, $user->getKey());
    // Never connected/offered.
    $account = SettlementAccount::factory()->create(['provider' => SettlementAccount::PROVIDER_STRIPE]);

    expect(fn () => app(PlaceOrderFromCart::class)->handle($cart, $account, cartCheckoutReturnUrl(), cartCheckoutBillingParty($user)))
        ->toThrow(RuntimeException::class);

    expect(Order::query()->where('settles_type', 'cart')->count())->toBe(0);
});

it('shows the cart page with lines, totals and offered accounts', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    cartCheckoutMixedCart($brand, $user->getKey());
    cartCheckoutAccount();

    test()->actingAs($user)
        ->get('/cart')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('cart/Show')
            ->has('cart.lines', 2)
            ->where('cart.totalGross', '83.30')
            ->has('cart.accounts', 1)
            ->where('cart.accounts.0.provider', 'stripe'));
});

it('shows the empty state when the payer has no open cart', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();

    test()->actingAs($user)
        ->get('/cart')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('cart/Show')
            ->where('cart.lines', [])
            ->where('cart.expiresAt', null));
});

it('adds a product line through the store endpoint', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $variant = cartCheckoutVariant(10);

    test()->actingAs($user)
        ->post('/cart/lines', ['variantId' => $variant->getKey(), 'quantity' => 3])
        ->assertRedirect();

    $cart = Cart::query()->open()->where('payer_user_id', $user->getKey())->sole();
    expect($cart->lines()->sole()->quantity)->toBe(3)
        ->and($variant->fresh()->stock_consumed)->toBe(3);
});

it('404s the store endpoint for an unpublished product\'s variant and consumes nothing', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    // Not published — the shop itself never links to this variant.
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product, 'product')->withCapacity(10)->create();

    test()->actingAs($user)
        ->post('/cart/lines', ['variantId' => $variant->getKey(), 'quantity' => 1])
        ->assertNotFound();

    expect($variant->fresh()->stock_consumed)->toBe(0)
        ->and(CartLine::query()->count())->toBe(0);
});

it('surfaces a quantity error when the store endpoint exhausts stock', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $variant = cartCheckoutVariant(2);

    test()->actingAs($user)
        ->post('/cart/lines', ['variantId' => $variant->getKey(), 'quantity' => 5])
        ->assertRedirect()
        ->assertSessionHasErrors('quantity');

    expect($variant->fresh()->stock_consumed)->toBe(0);
});

it('updates a product line quantity through the update endpoint', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $variant = cartCheckoutVariant(10);
    $cart = OpenCart::for($brand->getKey(), $user->getKey());
    $line = app(AddProductToCart::class)->handle($cart, $variant, 2);

    test()->actingAs($user)
        ->patch("/cart/lines/{$line->id}", ['quantity' => 5])
        ->assertRedirect();

    expect($line->fresh()->quantity)->toBe(5)
        ->and($variant->fresh()->stock_consumed)->toBe(5);
});

it('refuses a quantity change on a registration line through the update endpoint', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $cart = OpenCart::for($brand->getKey(), $user->getKey());
    $line = CartLine::factory()->forCart($cart)->registration()->create();

    test()->actingAs($user)
        ->patch("/cart/lines/{$line->id}", ['quantity' => 2])
        ->assertSessionHasErrors('quantity');
});

it('404s a line belonging to another payer\'s cart', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $cart = OpenCart::for($brand->getKey(), $owner->getKey());
    $line = CartLine::factory()->forCart($cart)->registration()->create();

    test()->actingAs($intruder)
        ->delete("/cart/lines/{$line->id}")
        ->assertNotFound();
});

it('removes a product line through the destroy endpoint and releases its stock', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $variant = cartCheckoutVariant(10);
    $cart = OpenCart::for($brand->getKey(), $user->getKey());
    $line = app(AddProductToCart::class)->handle($cart, $variant, 4);

    test()->actingAs($user)
        ->delete("/cart/lines/{$line->id}")
        ->assertRedirect();

    expect(CartLine::query()->whereKey($line->id)->exists())->toBeFalse()
        ->and($variant->fresh()->stock_consumed)->toBe(0);
});

it('sends a guest to login', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);

    test()->get('/cart')->assertRedirect('/login');
});

it('404s the cart when the brand has not switched the shop feature on', function (): void {
    $brand = test()->withBrand();
    $user = User::factory()->create();

    test()->actingAs($user)->get('/cart')->assertNotFound();
});

it('shares cart.count for a signed-in payer and zero otherwise', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();

    // A guest sees no count at all.
    test()->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('cart.count', 0));

    // Signed in, no cart yet.
    test()->actingAs($user)
        ->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('cart.count', 0));

    $variant = cartCheckoutVariant(10);
    $cart = OpenCart::for($brand->getKey(), $user->getKey());
    app(AddProductToCart::class)->handle($cart, $variant, 2);
    CartLine::factory()->forCart($cart)->registration()->create();

    test()->actingAs($user)
        ->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('cart.count', 2));
});

it('is zero when the shop feature is off even for a signed-in member with an old cart row', function (): void {
    $brand = test()->withBrand();
    $user = User::factory()->create();

    test()->actingAs($user)
        ->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('cart.count', 0));
});

/*
|--------------------------------------------------------------------------
| Consent at the cart checkout (GitLab #2)
|--------------------------------------------------------------------------
|
| "Zahlungspflichtig bestellen" is where the contract is concluded, so the
| AGB and the Widerrufsbelehrung have to be in front of the payer before it,
| and the agreement is recorded as a Consent row against the exact policy
| version — never as a checkbox nobody kept.
|
| Commerce never writes that row: it emits CheckoutConsentGiven and Compliance
| records it (ADR-0004 — events are the only contract between modules, and
| the architecture test allows nothing else).
|
*/

/**
 * The two purchase texts published for the brand in context, operator-wide
 * so the fallback is what the checkout reads (#76).
 */
function cartCheckoutLegalTexts(): void
{
    foreach ([['terms', 'Terms of Participation'], ['withdrawal', 'Withdrawal Information']] as [$slug, $name]) {
        $policy = Policy::factory()->operatorWide()->create(['slug' => $slug, 'name' => $name]);
        PolicyVersion::factory()->for($policy)->operatorWide()->published()->create([
            'version' => $slug.'-v1',
            'requires_consent' => $slug === 'terms',
        ]);
    }
}

it('puts the purchase texts and the withdrawal exemption on the cart page', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    cartCheckoutLegalTexts();
    $user = User::factory()->create();
    cartCheckoutMixedCart($brand, $user->getKey());

    test()->actingAs($user)
        ->get('/cart')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('cart/Show')
            ->has('consentSlots', 1)
            ->where('consentSlots.0.component', 'compliance/CheckoutConsent')
            ->where('consentSlots.0.props.required', true)
            ->where('consentSlots.0.props.policies.0.slug', 'terms')
            ->where('consentSlots.0.props.policies.0.url', '/policies/terms')
            ->where('consentSlots.0.props.policies.1.slug', 'withdrawal')
            ->where('consentSlots.0.props.exemptionNotice', CheckoutLegalTexts::EXEMPTION_NOTICE));
});

it('claims no agreement on the cart page of an instance with no published texts', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    cartCheckoutMixedCart($brand, $user->getKey());

    test()->actingAs($user)
        ->get('/cart')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('consentSlots.0.props.required', false)
            ->where('consentSlots.0.props.policies', []));
});

it('refuses a cart checkout that did not accept the terms, and places no order', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    cartCheckoutLegalTexts();
    $user = User::factory()->create();
    cartCheckoutMixedCart($brand, $user->getKey());
    $account = cartCheckoutAccount();

    test()->actingAs($user)
        ->post('/cart/checkout', ['account' => $account->id])
        ->assertRedirect()
        ->assertSessionHasErrors('accept_terms');

    expect(Order::query()->count())->toBe(0)
        ->and(Consent::query()->count())->toBe(0);
});

it('records one consent per purchase text against the current version when the cart is checked out', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    cartCheckoutLegalTexts();
    $user = User::factory()->create();
    cartCheckoutMixedCart($brand, $user->getKey());
    $account = cartCheckoutAccount();

    test()->actingAs($user)
        ->post('/cart/checkout', ['account' => $account->id, 'accept_terms' => true])
        ->assertRedirect('https://checkout.stripe.test/cart-session');

    $consents = Consent::query()->where('subject_id', $user->getKey())->get();

    expect($consents)->toHaveCount(2)
        ->and($consents->pluck('capture_context')->unique()->all())->toBe(['checkout'])
        ->and($consents->pluck('brand_id')->unique()->all())->toBe([$brand->getKey()]);

    $versions = PolicyVersion::withoutBrandScope()
        ->whereIn('id', $consents->pluck('policy_version_id'))
        ->pluck('version')
        ->sort()
        ->values()
        ->all();

    expect($versions)->toBe(['terms-v1', 'withdrawal-v1']);
});

it('checks out and records nothing on an instance whose purchase texts are unpublished', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    cartCheckoutMixedCart($brand, $user->getKey());
    $account = cartCheckoutAccount();

    // No accept_terms in the payload: there is nothing to accept, and a
    // checkout that demanded agreement to a document nobody published would
    // be unusable on a fresh instance.
    test()->actingAs($user)
        ->post('/cart/checkout', ['account' => $account->id])
        ->assertRedirect('https://checkout.stripe.test/cart-session');

    expect(Order::query()->count())->toBe(1)
        ->and(Consent::query()->count())->toBe(0);
});

/**
 * Task 17b (2026-09-21): every line on the cart page links to its page —
 * the registration line to the registration, the product line to the shop
 * product page (`Cart\ProductPageUrls`).
 */
it('links each cart line to its registration or its shop product page', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $registrationId = (string) Str::ulid();
    cartCheckoutMixedCart($brand, $user->getKey(), $registrationId);
    $slug = Product::query()->sole()->slug;

    test()->actingAs($user)
        ->get('/cart')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cart.lines.0.kind', 'product')
            ->where('cart.lines.0.url', '/shop/'.$slug)
            ->where('cart.lines.1.kind', 'registration')
            ->where('cart.lines.1.url', '/my/registrations/'.$registrationId));
});
