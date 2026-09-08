<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Actions\AddProductToCart;
use Konventix\Commerce\Actions\OpenCart;
use Konventix\Commerce\Actions\PlaceOrderFromCart;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Actions\SendInvoiceMail;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Documents\InvoiceDocumentRenderer;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\CartCheckedOut;
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
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
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
final class CartCheckoutTestFakeStripe implements StripeApi
{
    /** @var list<array<string, mixed>> */
    public array $sessions = [];

    public function createCheckoutSession(array $params): StripeCheckoutSession
    {
        $this->sessions[] = $params;

        return new StripeCheckoutSession(id: 'cs_cart_'.count($this->sessions), url: 'https://checkout.stripe.test/cart-session');
    }

    /**
     * Nothing took this payment's money back (#235). Every fake here answers
     * the empty pair unless its own test says otherwise.
     *
     * @return array{disputes: list<array{id: string, status: string, amount: int, currency: string, reason: ?string, fee: ?int, created: ?int}>, refunds: list<array{id: string, amount: int, currency: string, reason: ?string, created: ?int}>}
     */
    public function fetchPaymentIntentReversals(string $paymentIntentId): array
    {
        return ['disputes' => [], 'refunds' => []];
    }

    public function createRefund(array $params): string
    {
        return 're_test_fake';
    }

    /**
     * Unused here: only `commerce:reconcile-payments` (#71) reads a session
     * back, and this fake never drives it.
     *
     * @return array{status: string, payment_status: string, payment_intent: ?string, payment_intent_status: ?string}
     */
    public function fetchCheckoutSession(string $sessionId): array
    {
        return [
            'status' => 'open',
            'payment_status' => 'unpaid',
            'payment_intent' => null,
            'payment_intent_status' => null,
        ];
    }
}

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

function cartCheckoutAccount(): SettlementAccount
{
    // A Stripe row is the brand's enablement flag; what makes it offered is
    // this instance holding a chargeable Stripe account — the secret key and
    // the signing secret both (ADR-0058 point 3, ADR-0039 point 2).
    config()->set('services.stripe.secret', 'sk_test_cart');
    config()->set('services.stripe.webhook_secret', 'whsec_cart');

    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_STRIPE,
    ]);

    app()->instance(StripeApi::class, new CartCheckoutTestFakeStripe);

    return $account;
}

/**
 * The billing party `CartController::checkout()` builds from the payer's own
 * account (ADR-0048) — `PlaceOrderFromCart::handle()` needs one for every
 * checkout, direct-Action calls included.
 */
function cartCheckoutBillingParty(User $user): BillingPartyData
{
    return BillingPartyData::ofAccount($user->name, $user->email);
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

    $redirect = app(PlaceOrderFromCart::class)->handle($cart, $account, 'https://brand.test/cart', cartCheckoutBillingParty($user));

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

    app(PlaceOrderFromCart::class)->handle($cart, $account, 'https://brand.test/cart', cartCheckoutBillingParty($user));

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

    $redirect = app(PlaceOrderFromCart::class)->handle($cart, $account, 'https://brand.test/cart', cartCheckoutBillingParty($user));
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

    $redirect = app(PlaceOrderFromCart::class)->handle($cart, $account, 'https://brand.test/cart', cartCheckoutBillingParty($user));
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

it('reuses the pending order on a second checkout of the same cart', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $cart = cartCheckoutMixedCart($brand, $user->getKey());
    $account = cartCheckoutAccount();

    app(PlaceOrderFromCart::class)->handle($cart, $account, 'https://brand.test/cart', cartCheckoutBillingParty($user));
    $orderCountAfterFirst = Order::query()->where('settles_type', 'cart')->count();
    $expiresAfterFirst = $cart->fresh()->expires_at;

    // A little time passes before the payer retries — the reused branch
    // must extend the grace window again, the same way `touchExpiry()`
    // extends the shopping one on continued activity.
    test()->travel(5)->minutes();

    app(PlaceOrderFromCart::class)->handle($cart->fresh(), $account, 'https://brand.test/cart', cartCheckoutBillingParty($user));

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

    expect(fn () => app(PlaceOrderFromCart::class)->handle($cart, $account, 'https://brand.test/cart', cartCheckoutBillingParty($user)))
        ->toThrow(RuntimeException::class);

    expect(Order::query()->where('settles_type', 'cart')->count())->toBe(0);
});

it('refuses to check out an empty cart', function (): void {
    $brand = cartCheckoutBrand();
    test()->activateFeature('commerce:shop', $brand);
    $user = User::factory()->create();
    $cart = Cart::factory()->forPayer($user->getKey())->create();
    $account = cartCheckoutAccount();

    expect(fn () => app(PlaceOrderFromCart::class)->handle($cart, $account, 'https://brand.test/cart', cartCheckoutBillingParty($user)))
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

    expect(fn () => app(PlaceOrderFromCart::class)->handle($cart, $account, 'https://brand.test/cart', cartCheckoutBillingParty($user)))
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
