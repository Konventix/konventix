<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\CheckedInAdmission;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * `/pay/order/{order}` — the order-addressed way back to a payment that
 * failed (GitLab #5, review 6 findings 1, 2 and 4).
 *
 * Order-scoped rather than cart-scoped: `OpenCart::for()` opens a second
 * cart the moment the payer adds one more product, so "the newest cart" is
 * not the one the mail was about. Naming the order cannot be ambiguous, and
 * it keeps the cart page — whose line controls need an open cart — out of
 * this entirely.
 *
 * The reservation is the first placement's. Arriving here, or paying from
 * here, never renews it: `ExpireCarts` owns when it ends (ADR-0047 point 4)
 * and this path never calls `PlaceOrderFromCart`'s reuse branch.
 *
 * Helper names prefixed `orderRepay*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
final class OrderRepayTestFakeStripe implements StripeApi
{
    /** @var list<array<string, mixed>> */
    public array $sessions = [];

    public function createCheckoutSession(array $params): StripeCheckoutSession
    {
        $this->sessions[] = $params;

        return new StripeCheckoutSession(id: 'cs_repay_'.count($this->sessions), url: 'https://checkout.stripe.test/repay-'.count($this->sessions));
    }

    /**
     * Nothing took this payment's money back (#235). Every fake here answers
     * the empty pair unless its own test says otherwise.
     *
     * @return array{disputes: list<array{id: string, status: string, amount: int, currency: string, reason: ?string, fee: ?int, created: ?int}>, refunds: list<array{id: string, amount: int, currency: string, reason: ?string, charge: ?string, created: ?int}>}
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
     * @return array{status: string, payment_status: string, payment_intent: ?string, payment_intent_status: ?string}
     */
    public function fetchCheckoutSession(string $sessionId): array
    {
        return ['status' => 'open', 'payment_status' => 'unpaid', 'payment_intent' => null, 'payment_intent_status' => null];
    }
}

function orderRepayBrand(): Brand
{
    $brand = test()->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();
    test()->activateFeature('commerce:shop', $brand);

    return $brand;
}

function orderRepayAccount(): SettlementAccount
{
    config()->set('services.stripe.secret', 'sk_test_repay');
    config()->set('services.stripe.webhook_secret', 'whsec_repay');

    $account = SettlementAccount::factory()->create(['provider' => SettlementAccount::PROVIDER_STRIPE]);

    app()->instance(StripeApi::class, new OrderRepayTestFakeStripe);

    return $account;
}

/**
 * A cart order whose hosted checkout failed: order pending, one payment row
 * in `failed`, the cart `checked_out` and still holding.
 *
 * @return array{0: Order, 1: Cart}
 */
function orderRepayFailedCartOrder(User $payer, bool $held = true): array
{
    $order = Order::factory()->create([
        'settles_type' => 'cart',
        'payer_user_id' => $payer->getKey(),
        'status' => OrderStatus::Pending,
        'total_net' => '30.00',
        'total_tax' => '5.70',
        'total_gross' => '35.70',
        'currency' => 'EUR',
    ]);
    // A product line of a published product (Task 17b, 2026-09-21): the
    // page links it to the shop page.
    $variant = ProductVariant::factory()
        ->for(Product::factory()->published()->create(['slug' => 'con-shirt']), 'product')
        ->create();
    OrderLine::factory()->forOrder($order)->create([
        'label' => 'Con Shirt',
        'quantity' => 1,
        'line_gross' => '35.70',
        'settles_kind' => 'product',
        'settles_ref' => $variant->getKey(),
    ]);

    $cart = Cart::factory()->create([
        'payer_user_id' => $payer->getKey(),
        'status' => CartStatus::CheckedOut,
        'checked_out_order_id' => $order->getKey(),
        'expires_at' => $held ? now()->addMinutes(30) : now()->subMinute(),
    ]);
    CartLine::factory()->forCart($cart)->create(['label' => 'Con Shirt', 'quantity' => 1, 'unit_amount_net' => '30.00']);

    Payment::factory()->create(['order_id' => $order->getKey(), 'status' => PaymentStatus::Failed]);

    return [$order->fresh(), $cart->fresh()];
}

it('shows the order, its hold and the failed attempt to the buyer', function (): void {
    orderRepayBrand();
    $payer = User::factory()->create();
    [$order, $cart] = orderRepayFailedCartOrder($payer);
    orderRepayAccount();

    test()->actingAs($payer)
        ->get('/pay/order/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pay/Order')
            ->where('order.id', $order->getKey())
            ->where('order.totalGross', '35.70')
            ->has('order.lines', 1)
            ->where('order.lines.0.label', 'Con Shirt')
            ->where('order.lines.0.productUrl', '/shop/con-shirt')
            ->where('paymentStatus', 'failed')
            ->where('payable', true)
            ->where('refusalReason', null)
            ->where('holdUntil', $cart->expires_at->toIso8601String())
            ->has('accounts', 1));
});

it('404s the page for anyone but the order\'s own buyer', function (): void {
    orderRepayBrand();
    $payer = User::factory()->create();
    [$order] = orderRepayFailedCartOrder($payer);

    // The id travels in mails and browser history, so knowing it authorizes
    // nothing — unlike the registration pay page, which is addressed by an
    // unguessable ULID the registrant's own flow carries.
    test()->actingAs(User::factory()->create())
        ->get('/pay/order/'.$order->getKey())
        ->assertNotFound();
});

it('404s an order that does not exist, and asks a guest to sign in', function (): void {
    orderRepayBrand();
    $missing = (string) Str::ulid();

    test()->get('/pay/order/'.$missing)->assertRedirect('/login');

    test()->actingAs(User::factory()->create())
        ->get('/pay/order/'.$missing)
        ->assertNotFound();
});

it('pays the same order again, writing one more payment row and no second order', function (): void {
    orderRepayBrand();
    $payer = User::factory()->create();
    [$order] = orderRepayFailedCartOrder($payer);
    $account = orderRepayAccount();

    test()->actingAs($payer)
        ->post('/pay/order/'.$order->getKey().'/start', ['account' => $account->getKey()])
        ->assertRedirect('https://checkout.stripe.test/repay-1');

    expect(Order::query()->count())->toBe(1)
        ->and(Payment::withoutBrandScope()->where('order_id', $order->getKey())->count())->toBe(2)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending);
});

/**
 * Review 6 finding 3: before this page existed the retry ran through
 * `PlaceOrderFromCart`'s reuse branch, which pushes `expires_at` another
 * `CHECKOUT_GRACE_MINUTES` out on every attempt — so a payer re-posting the
 * checkout every 59 minutes could hold stock and registration lines
 * indefinitely and `ExpireCarts` would never sweep the cart. The
 * order-scoped path never touches the cart at all.
 */
it('never renews the reservation the first placement fixed', function (): void {
    orderRepayBrand();
    $payer = User::factory()->create();
    [$order, $cart] = orderRepayFailedCartOrder($payer);
    $account = orderRepayAccount();
    $deadline = $cart->expires_at;

    test()->actingAs($payer)->get('/pay/order/'.$order->getKey())->assertOk();

    test()->actingAs($payer)
        ->post('/pay/order/'.$order->getKey().'/start', ['account' => $account->getKey()])
        ->assertRedirect('https://checkout.stripe.test/repay-1');

    expect($cart->fresh()->expires_at->toIso8601String())->toBe($deadline->toIso8601String())
        ->and($cart->fresh()->status)->toBe(CartStatus::CheckedOut);
});

it('refuses to take money once the reservation has run out, and says so on the page', function (): void {
    orderRepayBrand();
    $payer = User::factory()->create();
    [$order] = orderRepayFailedCartOrder($payer, held: false);
    $account = orderRepayAccount();

    test()->actingAs($payer)
        ->get('/pay/order/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payable', false)
            ->where('refusalReason', 'The reservation for this order has run out.'));

    test()->actingAs($payer)
        ->post('/pay/order/'.$order->getKey().'/start', ['account' => $account->getKey()])
        ->assertRedirect()
        ->assertSessionHasErrors('account');

    expect(Payment::withoutBrandScope()->where('order_id', $order->getKey())->count())->toBe(1);
});

it('refuses an order that is already settled or no longer awaiting payment', function (): void {
    orderRepayBrand();
    $payer = User::factory()->create();
    [$order] = orderRepayFailedCartOrder($payer);
    $account = orderRepayAccount();

    Payment::factory()->create(['order_id' => $order->getKey(), 'status' => PaymentStatus::Succeeded]);

    test()->actingAs($payer)
        ->get('/pay/order/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payable', false)
            ->where('refusalReason', 'This order is already settled.'));

    $order->forceFill(['status' => OrderStatus::Cancelled])->save();

    test()->actingAs($payer)
        ->get('/pay/order/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('refusalReason', 'This order is no longer awaiting payment.'));
});

/**
 * Bugfix round 2026-09-21, Task 3: this page had no paid branch, so a
 * provider returning a successful payer here greeted them with the red
 * "already settled" refusal. Checkouts return to `/my/orders/{order}` now,
 * but a mailed link still lands here, and a paid order reads as the
 * confirmation the page derives from `order.status`.
 */
it('shows a paid order as settled rather than refused', function (): void {
    orderRepayBrand();
    $payer = User::factory()->create();
    [$order] = orderRepayFailedCartOrder($payer);
    orderRepayAccount();

    Payment::factory()->succeeded()->create(['order_id' => $order->getKey()]);
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    test()->actingAs($payer)
        ->get('/pay/order/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pay/Order')
            ->where('order.status', 'paid')
            ->where('payable', false)
            ->where('refusalReason', 'This order is no longer awaiting payment.'));
});

it('sends the payer to the consent screen before the form when a contract text is outstanding', function (): void {
    orderRepayBrand();
    $payer = User::factory()->create();
    [$order] = orderRepayFailedCartOrder($payer);
    orderRepayAccount();

    PolicyVersion::factory()->for(Policy::factory()->operatorWide()->terms())->operatorWide()->published()->create([
        'version' => 'terms-v1',
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Contract,
    ]);

    test()->actingAs($payer)
        ->get('/pay/order/'.$order->getKey())
        ->assertRedirect(route('policies.required', absolute: false))
        ->assertSessionHas('url.intended', '/pay/order/'.$order->getKey());
});

/**
 * ADR-0057 point 3, GitLab #186: a reversal revives the receivable, and a
 * cart order is then "exactly like a pending one" again — except that the
 * chargeback lands days after `Cart::CHECKOUT_GRACE_MINUTES` has run out,
 * so the reservation refusal used to close the only self-service way back
 * to the money for every reversed cart order there is.
 *
 * Nothing is reserved any more that could be sold twice: `ExpireCarts`
 * only ever cancels an order that is still `pending`, so a reversed one is
 * never swept, and the seats and stock its lines hold were handed over
 * when it was paid the first time.
 */
it('takes a reversed cart order past the reservation the first payment used', function (): void {
    orderRepayBrand();
    $payer = User::factory()->create();
    [$order] = orderRepayFailedCartOrder($payer, held: false);
    $account = orderRepayAccount();

    Payment::factory()->create(['order_id' => $order->getKey(), 'status' => PaymentStatus::Reversed]);
    $order->forceFill(['status' => OrderStatus::PaymentReversed])->save();

    test()->actingAs($payer)
        ->get('/pay/order/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.status', 'payment_reversed')
            ->where('payable', true)
            ->where('refusalReason', null));

    test()->actingAs($payer)
        ->post('/pay/order/'.$order->getKey().'/start', ['account' => $account->getKey()])
        ->assertRedirect('https://checkout.stripe.test/repay-1');

    expect(Payment::withoutBrandScope()->where('order_id', $order->getKey())->count())->toBe(3);
});

/**
 * The deadline the reversal put on the money (ADR-0057 point 4), on the
 * order-addressed page too — `pay/Show.vue` has carried it since the
 * registration half of #186's sibling and this page said nothing.
 */
it('names the day a reversed cart order is owed back by', function (): void {
    orderRepayBrand();
    $payer = User::factory()->create();
    [$order] = orderRepayFailedCartOrder($payer, held: false);
    orderRepayAccount();

    $payment = Payment::factory()->create(['order_id' => $order->getKey(), 'status' => PaymentStatus::Reversed]);
    $order->forceFill(['status' => OrderStatus::PaymentReversed])->save();
    PaymentReversal::factory()->create([
        'payment_id' => $payment->getKey(),
        'order_id' => $order->getKey(),
        'status' => ReversalStatus::Final,
        'occurred_at' => now()->subDay(),
        'repay_due_on' => now()->addDays(6)->toDateString(),
    ]);

    test()->actingAs($payer)
        ->get('/pay/order/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('repayDueOn', now()->addDays(6)->toDateString()));
});

/**
 * Review finding 4, ruled: a reversed order is payable until the day it was
 * owed back by, and not one day longer. Before this the page kept offering
 * the button until `commerce:cancel-unpaid-reversals` next ran — a window of
 * up to a day in which the payer was invited to settle money the brand had
 * already stopped asking for.
 *
 * Whole days, matching the sweep's own comparison: the deadline's own day is
 * still payable, the day after is not.
 */
it('stops taking a reversed order the day after the money was owed back', function (): void {
    orderRepayBrand();
    $payer = User::factory()->create();
    [$order] = orderRepayFailedCartOrder($payer, held: false);
    $account = orderRepayAccount();

    $payment = Payment::factory()->create(['order_id' => $order->getKey(), 'status' => PaymentStatus::Reversed]);
    $order->forceFill(['status' => OrderStatus::PaymentReversed])->save();
    $reversal = PaymentReversal::factory()->create([
        'payment_id' => $payment->getKey(),
        'order_id' => $order->getKey(),
        'status' => ReversalStatus::Final,
        'occurred_at' => now()->subDays(8),
        'repay_due_on' => now()->toDateString(),
    ]);

    // On the day itself: still payable.
    test()->actingAs($payer)
        ->get('/pay/order/'.$order->getKey())
        ->assertInertia(fn (AssertableInertia $page) => $page->where('payable', true));

    $reversal->forceFill(['repay_due_on' => now()->subDay()->toDateString()])->save();

    test()->actingAs($payer)
        ->get('/pay/order/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payable', false)
            ->where('refusalReason', 'The deadline to put this payment back was '.now()->subDay()->toDateString().'.'));

    test()->actingAs($payer)
        ->post('/pay/order/'.$order->getKey().'/start', ['account' => $account->getKey()])
        ->assertRedirect()
        ->assertSessionHasErrors('account');
});

/**
 * Re-review N2: `commerce:cancel-unpaid-reversals` deliberately does *not*
 * cancel a reversed order whose admission was already used — somebody
 * attended, so what they owe is a receivable, and the sweep hands it to the
 * dunning ladder with `overdue_at` set to the deadline it missed. That
 * order's `repay_due_on` is then permanently in the past, and a page that
 * refused on the date alone would dead-end the very "Pay now" link the brand
 * is dunning for.
 */
it('keeps taking a lapsed reversal the sweep leaves as a receivable after check-in', function (): void {
    orderRepayBrand();
    $payer = User::factory()->create();
    $registrationId = (string) Str::ulid();

    $order = Order::factory()->create([
        'settles_type' => 'cart',
        'payer_user_id' => $payer->getKey(),
        'status' => OrderStatus::PaymentReversed,
        'total_gross' => '35.70',
        'currency' => 'EUR',
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'label' => 'Weekend Ticket',
        'settles_kind' => 'registration',
        'settles_ref' => $registrationId,
    ]);
    $account = orderRepayAccount();

    $payment = Payment::factory()->create(['order_id' => $order->getKey(), 'status' => PaymentStatus::Reversed]);
    PaymentReversal::factory()->create([
        'payment_id' => $payment->getKey(),
        'order_id' => $order->getKey(),
        'status' => ReversalStatus::Final,
        'kind' => ReversalKind::Return,
        'occurred_at' => now()->subDays(20),
        'repay_due_on' => now()->subDays(13)->toDateString(),
    ]);

    // Nobody attended yet: the sweep would cancel, so the page refuses.
    test()->actingAs($payer)
        ->get('/pay/order/'.$order->getKey())
        ->assertInertia(fn (AssertableInertia $page) => $page->where('payable', false));

    CheckedInAdmission::query()->create([
        'admission_id' => (string) Str::ulid(),
        'registration_id' => $registrationId,
        'first_checked_in_at' => now()->subDays(15),
    ]);

    test()->actingAs($payer)
        ->get('/pay/order/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payable', true)
            ->where('refusalReason', null));

    test()->actingAs($payer)
        ->post('/pay/order/'.$order->getKey().'/start', ['account' => $account->getKey()])
        ->assertRedirect('https://checkout.stripe.test/repay-1');
});

/**
 * N9: the deadline reversal is picked the way the sweep picks it — the
 * newest final `return` or `dispute`. An `external_refund` is not a
 * receivable and must not close the page.
 */
it('ignores an external refund when deciding the repayment deadline', function (): void {
    orderRepayBrand();
    $payer = User::factory()->create();
    [$order] = orderRepayFailedCartOrder($payer, held: false);
    orderRepayAccount();

    $payment = Payment::factory()->create(['order_id' => $order->getKey(), 'status' => PaymentStatus::Reversed]);
    $order->forceFill(['status' => OrderStatus::PaymentReversed])->save();
    PaymentReversal::factory()->create([
        'payment_id' => $payment->getKey(),
        'order_id' => $order->getKey(),
        'status' => ReversalStatus::Final,
        'kind' => ReversalKind::ExternalRefund,
        'occurred_at' => now()->subDays(20),
        'repay_due_on' => now()->subDays(13)->toDateString(),
    ]);

    test()->actingAs($payer)
        ->get('/pay/order/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payable', true)
            ->where('repayDueOn', null));
});
