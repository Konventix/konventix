<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Templates\TemplateRenderer;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * GitLab #5: the confirmation of an unpaid order says where to pay it, and
 * the payer's own order list offers the same way back.
 *
 * Fix round 1: that destination is *order*-addressed — `/pay/order/{order}`
 * for every order that is not a registration — so a payer who kept shopping
 * after an abandoned checkout can neither be shown nor check out the wrong
 * cart (review 6 findings 1, 2 and 4). The page itself is
 * `OrderRepayPageTest`; this file covers the links that lead to it.
 *
 * The window is `ExpireCarts`' second pass (ADR-0047 point 4): a
 * `checked_out` cart holds its stock and its registration lines for
 * `Cart::CHECKOUT_GRACE_MINUTES`, and only that sweep releases them. Nothing
 * here changes it.
 *
 * Helper names prefixed `payLink*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
final class PayLinkTestFakeStripe implements StripeApi
{
    /** @var list<array<string, mixed>> */
    public array $sessions = [];

    public function createCheckoutSession(array $params): StripeCheckoutSession
    {
        $this->sessions[] = $params;

        return new StripeCheckoutSession(id: 'cs_paylink_'.count($this->sessions), url: 'https://checkout.stripe.test/paylink-'.count($this->sessions));
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
     * @return array{status: string, payment_status: string, payment_intent: ?string, payment_intent_status: ?string}
     */
    public function fetchCheckoutSession(string $sessionId): array
    {
        return ['status' => 'open', 'payment_status' => 'unpaid', 'payment_intent' => null, 'payment_intent_status' => null];
    }
}

function payLinkBrand(): Brand
{
    $brand = test()->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();
    test()->activateFeature('commerce:shop', $brand);

    return $brand;
}

function payLinkStripeAccount(): SettlementAccount
{
    config()->set('services.stripe.secret', 'sk_test_paylink');
    config()->set('services.stripe.webhook_secret', 'whsec_paylink');

    $account = SettlementAccount::factory()->create(['provider' => SettlementAccount::PROVIDER_STRIPE]);

    app()->instance(StripeApi::class, new PayLinkTestFakeStripe);

    return $account;
}

/**
 * A cart order mid-checkout: the order pending, the cart `checked_out` and
 * still holding, exactly as `PlaceOrderFromCart` leaves them.
 *
 * @return array{0: Order, 1: Cart}
 */
function payLinkCheckedOutCartOrder(Brand $brand, User $payer, bool $held = true): array
{
    $order = Order::factory()->create([
        'settles_type' => 'cart',
        'payer_user_id' => $payer->getKey(),
        'status' => OrderStatus::Pending,
        'total_gross' => '35.70',
        'currency' => 'EUR',
    ]);
    OrderLine::factory()->forOrder($order)->create(['label' => 'Con Shirt', 'quantity' => 1, 'line_gross' => '35.70']);

    $cart = Cart::factory()->create([
        'payer_user_id' => $payer->getKey(),
        'status' => CartStatus::CheckedOut,
        'checked_out_order_id' => $order->getKey(),
        'expires_at' => $held ? now()->addMinutes(30) : now()->subMinute(),
    ]);

    CartLine::factory()->forCart($cart)->create([
        'label' => 'Con Shirt',
        'quantity' => 1,
        'unit_amount_net' => '30.00',
    ]);

    return [$order->fresh(), $cart->fresh()];
}

function payLinkPlaceOrder(Brand $brand, Cart $cart): void
{
    event(new OrderPlaced(
        orderId: (string) $cart->checked_out_order_id,
        brandId: $brand->getKey(),
        settlesType: 'cart',
        settlesId: $cart->getKey(),
        totalNet: '30.00',
        totalGross: '35.70',
        currency: 'EUR',
        placedAt: now()->toIso8601String(),
    ));
}

function payLinkConfirmation(): SendQueuedMessage
{
    return Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.order-confirmation',
    )->sole();
}

it("puts the order's own pay page on the confirmation of an unpaid cart order", function (): void {
    $brand = payLinkBrand();
    $payer = orderMailsRecipient($brand->getKey());
    [$order, $cart] = payLinkCheckedOutCartOrder($brand, $payer);
    Queue::fake();

    payLinkPlaceOrder($brand, $cart);

    expect(payLinkConfirmation()->message->payload->toArray()['payUrl'])
        ->toEndWith('/pay/order/'.$order->getKey());
});

it('keeps that link once the reservation is gone, because the page is what explains it', function (): void {
    $brand = payLinkBrand();
    $payer = orderMailsRecipient($brand->getKey());
    [$order, $cart] = payLinkCheckedOutCartOrder($brand, $payer, held: false);
    Queue::fake();

    payLinkPlaceOrder($brand, $cart);

    // Not `/my/orders`: `/pay/order/{order}` states "the reservation for this
    // order has run out" and refuses, which is a better answer to a payer
    // holding a mail than a list with no button on it (review 6 finding 4).
    expect(payLinkConfirmation()->message->payload->toArray()['payUrl'])
        ->toEndWith('/pay/order/'.$order->getKey());
});

it('leaves the pay link off the confirmation of an order that is already paid', function (): void {
    $brand = payLinkBrand();
    $payer = orderMailsRecipient($brand->getKey());
    [$order, $cart] = payLinkCheckedOutCartOrder($brand, $payer);
    $order->forceFill(['status' => OrderStatus::Paid])->save();
    Queue::fake();

    payLinkPlaceOrder($brand, $cart);

    expect(payLinkConfirmation()->message->payload->toArray()['payUrl'])->toBeNull();
});

it('renders the pay link in the confirmation mail body', function (): void {
    $brand = payLinkBrand();
    $payer = orderMailsRecipient($brand->getKey());
    [$order, $cart] = payLinkCheckedOutCartOrder($brand, $payer);
    Queue::fake();

    payLinkPlaceOrder($brand, $cart);

    expect(app(TemplateRenderer::class)->render(payLinkConfirmation()->message)->body)
        ->toContain('>Pay now</a>')
        ->toContain('/pay/order/'.$order->getKey().'"');
});

it("points a failed cart payment back at the order's own pay page", function (): void {
    $brand = payLinkBrand();
    $payer = orderMailsRecipient($brand->getKey());
    [$order] = payLinkCheckedOutCartOrder($brand, $payer);
    Queue::fake();

    event(new PaymentFailed(
        paymentId: (string) Str::ulid(),
        orderId: $order->getKey(),
        brandId: $brand->getKey(),
        settlementMethod: 'stripe',
        reasonCode: 'failed',
        failedAt: now()->toIso8601String(),
    ));

    $job = Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'commerce.payment-failed',
    )->sole();

    expect($job->message->payload->toArray()['retryUrl'])->toEndWith('/pay/order/'.$order->getKey());
});

it('gives a cart order the same pay link on the payer\'s own order list', function (): void {
    $brand = payLinkBrand();
    $payer = User::factory()->create();
    [$order] = payLinkCheckedOutCartOrder($brand, $payer);

    test()->actingAs($payer)
        ->get('/my/orders')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('orders.0.payUrl', '/pay/order/'.$order->getKey()));
});

it('leaves the cart page on open carts only, so its own line controls keep working', function (): void {
    $brand = payLinkBrand();
    $payer = User::factory()->create();
    payLinkCheckedOutCartOrder($brand, $payer);
    payLinkStripeAccount();

    // Review 6 finding 1: a `checked_out` cart is not rendered here. The
    // quantity and remove controls resolve through an open cart only, and a
    // page whose own buttons 404 is worse than the empty state. Re-paying is
    // `/pay/order/{order}`'s job.
    test()->actingAs($payer)
        ->get('/cart')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('cart/Show')
            ->where('cart.lines', []));
});

it('takes a second attempt on a registration order whose last payment failed, and writes a payment row per attempt', function (): void {
    payLinkBrand();
    $payer = User::factory()->create();
    $account = payLinkStripeAccount();

    $registrationId = (string) Str::ulid();
    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
        // The payer this link belongs to — since GitLab #185 the pay page
        // serves nobody else.
        'payer_user_id' => $payer->getKey(),
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);
    OrderLine::factory()->forOrder($order)->create(['label' => 'Weekend ticket', 'line_gross' => '119.00']);

    Payment::factory()->create([
        'order_id' => $order->getKey(),
        'settlement_account_id' => $account->getKey(),
        'status' => PaymentStatus::Expired,
    ]);

    test()->actingAs($payer)
        ->post('/pay/registration/'.$registrationId.'/start', ['account' => $account->getKey()])
        ->assertRedirect('https://checkout.stripe.test/paylink-1');

    expect(Payment::withoutBrandScope()->where('order_id', $order->getKey())->count())->toBe(2);
});

it('still refuses a second attempt once a payment has succeeded', function (): void {
    payLinkBrand();
    $payer = User::factory()->create();
    $account = payLinkStripeAccount();

    $registrationId = (string) Str::ulid();
    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
        'payer_user_id' => $payer->getKey(),
    ]);
    OrderLine::factory()->forOrder($order)->create();

    Payment::factory()->create([
        'order_id' => $order->getKey(),
        'settlement_account_id' => $account->getKey(),
        'status' => PaymentStatus::Succeeded,
    ]);

    test()->actingAs($payer)
        ->post('/pay/registration/'.$registrationId.'/start', ['account' => $account->getKey()])
        ->assertStatus(409);
});
