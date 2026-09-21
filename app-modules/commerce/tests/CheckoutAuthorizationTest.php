<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Identity\Models\User;

/**
 * Who may see and pay a registration order (GitLab #185).
 *
 * `/pay/registration/{ulid}` used to treat knowing the ULID as the
 * authorization: every signed-in visitor who had one — from a forwarded
 * mail, a shared screen, a guessed id — read the amount, the payment status
 * and, since ADR-0057, the reversal reason of somebody else's order, and
 * could start a hosted checkout on it.
 *
 * The rule is the one `showOrder()`/`startOrder()` already apply on
 * `/pay/order/{order}`: the order's own payer, or 404. 404 rather than 403
 * is the house answer for "not yours" here — `payableOrderFor()` and
 * `MyCreditNoteDocumentController` both use it, and it does not confirm to
 * a stranger that the id exists.
 *
 * Helper names prefixed `checkoutAuth*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
final class CheckoutAuthorizationTestFakeStripe implements StripeApi
{
    /** @var list<array<string, mixed>> */
    public array $sessions = [];

    public function createCheckoutSession(array $params): StripeCheckoutSession
    {
        $this->sessions[] = $params;

        return new StripeCheckoutSession(id: 'cs_auth_'.count($this->sessions), url: 'https://checkout.stripe.test/auth-session');
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

/**
 * A pending registration order owed by `$payerUserId` — null for the
 * manually booked one (`BookManualPayment` leaves the column empty, since
 * nobody paid for it online).
 *
 * @return array{0: string, 1: Order, 2: SettlementAccount}
 */
function checkoutAuthOrder(?string $payerUserId): array
{
    $registrationId = (string) Str::ulid();

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
        'payer_user_id' => $payerUserId,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    OrderLine::factory()->create([
        'order_id' => $order->id,
        'label' => 'Weekend ticket',
        'line_gross' => '119.00',
    ]);

    // Offered because this instance holds a chargeable Stripe account
    // (ADR-0058 point 3); the row is only the brand's enablement flag.
    config()->set('services.stripe.secret', 'sk_test_auth');
    config()->set('services.stripe.webhook_secret', 'whsec_auth');

    $account = SettlementAccount::factory()->create(['provider' => SettlementAccount::PROVIDER_STRIPE]);
    app()->instance(StripeApi::class, new CheckoutAuthorizationTestFakeStripe);

    return [$registrationId, $order, $account];
}

it('refuses another signed-in user the pay page of an order they do not owe (#185)', function (): void {
    $this->withBrand();
    $payer = User::factory()->create();
    [$registrationId] = checkoutAuthOrder($payer->getKey());

    $this->actingAs(User::factory()->create())
        ->get('/pay/registration/'.$registrationId)
        ->assertNotFound();
});

it('refuses another signed-in user a checkout start on an order they do not owe (#185)', function (): void {
    $this->withBrand();
    $payer = User::factory()->create();
    [$registrationId, $order, $account] = checkoutAuthOrder($payer->getKey());

    $this->actingAs(User::factory()->create())
        ->post('/pay/registration/'.$registrationId.'/start', ['account' => $account->getKey()])
        ->assertNotFound();

    // Refused before anything was started: no attempt row, so no provider
    // session was ever opened on somebody else's order.
    expect(Payment::withoutBrandScope()->where('order_id', $order->getKey())->count())->toBe(0);
});

it('refuses every signed-in user an order that names no payer (#185)', function (): void {
    // A manual booking (`BookManualPayment`) leaves `payer_user_id` empty:
    // it was settled by hand and has no business being paid online.
    $this->withBrand();
    [$registrationId, $order, $account] = checkoutAuthOrder(null);

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get('/pay/registration/'.$registrationId)
        ->assertNotFound();

    $this->actingAs($stranger)
        ->post('/pay/registration/'.$registrationId.'/start', ['account' => $account->getKey()])
        ->assertNotFound();

    expect(Payment::withoutBrandScope()->where('order_id', $order->getKey())->count())->toBe(0);
});

/**
 * `/my/orders/{order}` (bugfix round 2026-09-21, Task 3) is the page every
 * hosted checkout returns to, so its id travels to the provider and back;
 * it applies the same rule as both pay pages — the order's own payer, or
 * 404.
 */
it('refuses another signed-in user the order page of an order they do not owe', function (): void {
    $this->withBrand();
    $payer = User::factory()->create();
    [, $order] = checkoutAuthOrder($payer->getKey());

    $this->actingAs(User::factory()->create())
        ->get('/my/orders/'.$order->getKey())
        ->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->get('/my/orders/'.(string) Str::ulid())
        ->assertNotFound();

    $this->actingAs($payer)
        ->get('/my/orders/'.$order->getKey())
        ->assertOk();
});

it('refuses every signed-in user the order page of an order that names no payer', function (): void {
    $this->withBrand();
    [, $order] = checkoutAuthOrder(null);

    $this->actingAs(User::factory()->create())
        ->get('/my/orders/'.$order->getKey())
        ->assertNotFound();
});

it('still sends a guest to sign in rather than answering 404', function (): void {
    // The route's `auth` middleware runs before the controller (#2), so a
    // guest keeps meeting the login redirect that carries a mailed pay link
    // back to this page — the ownership check never sees them.
    $this->withBrand();
    $payer = User::factory()->create();
    [$registrationId, $order, $account] = checkoutAuthOrder($payer->getKey());

    $this->get('/pay/registration/'.$registrationId)->assertRedirect('/login');
    $this->post('/pay/registration/'.$registrationId.'/start', ['account' => $account->getKey()])
        ->assertRedirect('/login');
    $this->get('/my/orders/'.$order->getKey())->assertRedirect('/login');
});

it('serves and starts the payment for the payer who owes the order', function (): void {
    $this->withBrand();
    $payer = User::factory()->create();
    [$registrationId, $order, $account] = checkoutAuthOrder($payer->getKey());

    // One settlement account, so step one is skipped (ADR-0066 D-CPL-07)
    // and the confirmation is what the payer is served.
    $this->actingAs($payer)
        ->get('/pay/registration/'.$registrationId.'/confirm')
        ->assertOk();

    $this->actingAs($payer)
        ->post('/pay/registration/'.$registrationId.'/start', ['account' => $account->getKey()])
        ->assertRedirect('https://checkout.stripe.test/auth-session');

    expect(Payment::withoutBrandScope()->where('order_id', $order->getKey())->count())->toBe(1);
});
