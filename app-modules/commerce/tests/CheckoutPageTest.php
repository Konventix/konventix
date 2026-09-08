<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\CheckoutLegalTexts;
use Konventix\Identity\Models\User;

/**
 * The public checkout page for `instant` settlement (ADR-0039), addressed
 * by registration ULID on the brand host.
 */
final class CheckoutPageTestFakeStripe implements StripeApi
{
    /** @var list<array<string, mixed>> */
    public array $sessions = [];

    public function createCheckoutSession(array $params): StripeCheckoutSession
    {
        $this->sessions[] = $params;

        return new StripeCheckoutSession(id: 'cs_page_'.count($this->sessions), url: 'https://checkout.stripe.test/session');
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

    public function createRefund(array $params): void {}

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

/**
 * The payer is signed in: both pay routes carry `auth` since fix round 1 of
 * #2 — a declaration at checkout needs a subject to record it against, and
 * Phase 1 requires an account anyway (decision-log Round 5 D-REG-08).
 *
 * @return array{0: string, 1: Order, 2: ?SettlementAccount, 3: User}
 */
function checkoutPageOrder(bool $stripeConnected = true): array
{
    test()->withBrand();

    $payer = User::factory()->create();
    test()->actingAs($payer);

    $registrationId = (string) Str::ulid();

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
        // The signed-in payer owes this order (ADR-0044). Since GitLab #185
        // that is also what authorizes the page: knowing the ULID is not.
        'payer_user_id' => $payer->getKey(),
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    OrderLine::factory()->create([
        'order_id' => $order->id,
        'label' => 'Weekend ticket',
        'line_gross' => '119.00',
    ]);

    $account = null;

    if ($stripeConnected) {
        // Offered because this instance holds a chargeable Stripe account —
        // key and signing secret both (ADR-0058 point 3, ADR-0039 point 2);
        // the row is only the brand's enablement flag.
        config()->set('services.stripe.secret', 'sk_test_page');
        config()->set('services.stripe.webhook_secret', 'whsec_page');

        $account = SettlementAccount::factory()->create([
            'provider' => SettlementAccount::PROVIDER_STRIPE,
        ]);
        app()->instance(StripeApi::class, new CheckoutPageTestFakeStripe);
    }

    return [$registrationId, $order, $account, $payer];
}

it('shows the order summary for a registration ULID', function (): void {
    [$registrationId] = checkoutPageOrder();

    $this->get('/pay/registration/'.$registrationId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pay/Show')
            ->where('order.totalGross', '119.00')
            ->where('order.lines.0.label', 'Weekend ticket')
            ->has('accounts', 1)
            ->where('accounts.0.provider', 'stripe')
            ->where('paymentStatus', null));
});

it('offers no online payment for a manual-only brand', function (): void {
    [$registrationId] = checkoutPageOrder(stripeConnected: false);

    $this->get('/pay/registration/'.$registrationId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('accounts', []));

    // Nothing is offered, so any account the payer might pick is refused —
    // a real Inertia POST redirects back with errors (302), same as any
    // other rejected form field (see PublicRegistrationFlowTest).
    $this->post('/pay/registration/'.$registrationId.'/start', ['account' => (string) Str::ulid()])
        ->assertRedirect()
        ->assertSessionHasErrors('account');
});

it('starts the hosted checkout and hard-redirects to the provider', function (): void {
    [$registrationId, $order, $account] = checkoutPageOrder();

    // Inertia::location: a plain request gets a real redirect to the
    // provider (SPA requests would get 409 + X-Inertia-Location).
    $this->post('/pay/registration/'.$registrationId.'/start', ['account' => $account->id])
        ->assertRedirect('https://checkout.stripe.test/session');

    expect(Payment::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);
});

it('refuses to start a checkout for a settled order', function (): void {
    [$registrationId, $order, $account] = checkoutPageOrder();

    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    $this->post('/pay/registration/'.$registrationId.'/start', ['account' => $account->id])
        ->assertStatus(409);
});

it('sends an unknown registration back to its own page with the reason', function (): void {
    // No longer a bare 404: `errors::404` renders a hard-coded "Not Found"
    // and drops the message `abort()` was given, so the sentence never
    // reached the person. `RegistrationCartPayRouteTest` pins the flash.
    checkoutPageOrder();
    $unknown = (string) Str::ulid();

    $this->get('/pay/registration/'.$unknown)
        ->assertRedirect('/my/registrations/'.$unknown);
});

it('pays the latest order when an amendment added a second one', function (): void {
    // ADR-0036: a registration maps to N orders; the payable one is newest.
    [$registrationId, , , $payer] = checkoutPageOrder();

    $delta = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
        'payer_user_id' => $payer->getKey(),
        'total_gross' => '35.70',
        'currency' => 'EUR',
        'created_at' => now()->addMinute(),
    ]);
    OrderLine::factory()->create(['order_id' => $delta->id, 'label' => 'Extra badge', 'line_gross' => '35.70']);

    $this->get('/pay/registration/'.$registrationId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.id', $delta->id)
            ->where('order.totalGross', '35.70'));
});

/*
|--------------------------------------------------------------------------
| Consent at the registration checkout (GitLab #2)
|--------------------------------------------------------------------------
|
| The same rule as the cart: the AGB and the Widerrufsbelehrung are in front
| of the payer before the order button, and the agreement is a Consent row
| against the exact version, not a checkbox nobody kept.
|
*/

/**
 * The two purchase texts published operator-wide for the brand in context
 * (#76), so the checkout reads the inherited fallback.
 */
function checkoutPageLegalTexts(): void
{
    foreach ([['terms', 'Terms of Participation'], ['withdrawal', 'Withdrawal Information']] as [$slug, $name]) {
        $policy = Policy::factory()->operatorWide()->create(['slug' => $slug, 'name' => $name]);
        PolicyVersion::factory()->for($policy)->operatorWide()->published()->create([
            'version' => $slug.'-v1',
            'requires_consent' => $slug === 'terms',
        ]);
    }
}

it('puts the purchase texts and the withdrawal exemption on the payment page', function (): void {
    [$registrationId] = checkoutPageOrder();
    checkoutPageLegalTexts();

    $this->get('/pay/registration/'.$registrationId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('consentSlots', 1)
            ->where('consentSlots.0.component', 'compliance/CheckoutConsent')
            ->where('consentSlots.0.props.required', true)
            ->where('consentSlots.0.props.policies.1.url', '/policies/withdrawal')
            ->where('consentSlots.0.props.exemptionNotice', CheckoutLegalTexts::EXEMPTION_NOTICE));
});

it('claims no agreement on the payment page of an instance with no published texts', function (): void {
    [$registrationId] = checkoutPageOrder();

    $this->get('/pay/registration/'.$registrationId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('consentSlots.0.props.required', false)
            ->where('consentSlots.0.props.policies', []));
});

it('refuses to start a checkout that did not accept the terms, and takes no payment', function (): void {
    [$registrationId, $order, $account] = checkoutPageOrder();
    checkoutPageLegalTexts();

    $this->post('/pay/registration/'.$registrationId.'/start', ['account' => $account->id])
        ->assertRedirect()
        ->assertSessionHasErrors('accept_terms');

    expect(Payment::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0);
});

it('records one consent per purchase text for the signed-in payer starting a checkout', function (): void {
    [$registrationId, , $account, $payer] = checkoutPageOrder();
    checkoutPageLegalTexts();

    $this->post('/pay/registration/'.$registrationId.'/start', [
        'account' => $account->id,
        'accept_terms' => true,
    ])->assertRedirect('https://checkout.stripe.test/session');

    $consents = Consent::query()->where('subject_id', $payer->getKey())->get();

    expect($consents)->toHaveCount(2)
        ->and($consents->pluck('capture_context')->unique()->all())->toBe(['checkout']);
});

/**
 * Fix round 1 of #2: both pay routes carry `auth` now. A declaration that
 * cannot be recorded is not a declaration — a guest used to be shown the
 * texts, forced to tick the box, and have the answer discarded because
 * `Consent.subject_id` needs a subject and the account-less subject identity
 * is still one of Compliance's open questions. Registration requires an
 * account anyway (D-REG-08) and the cart routes were already behind `auth`;
 * the intended-URL redirect keeps a mailed pay link working, it just asks
 * the payer to sign in first.
 */
it('sends a guest to sign in rather than taking a declaration it cannot record', function (): void {
    [$registrationId, $order, $account] = checkoutPageOrder();
    checkoutPageLegalTexts();

    auth()->logout();

    $this->get('/pay/registration/'.$registrationId)->assertRedirect('/login');

    $this->post('/pay/registration/'.$registrationId.'/start', [
        'account' => $account->id,
        'accept_terms' => true,
    ])->assertRedirect('/login');

    expect(Consent::query()->count())->toBe(0)
        ->and(Payment::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0);
});

/**
 * ADR-0057 point 3: a reversal revives the receivable — "the money comes
 * back by the pay link (#5), the door POS or a bank transfer". The payer's
 * `commerce.payment-reversed` mail sends a registration payer to exactly
 * this page, so the page and `start()` must take the money a second time.
 */
it('takes the money again for a registration order whose payment was reversed', function (): void {
    [$registrationId, $order, $account] = checkoutPageOrder();

    $order->forceFill(['status' => OrderStatus::PaymentReversed, 'paid_at' => now()->subWeek()])->save();

    $this->post('/pay/registration/'.$registrationId.'/start', ['account' => $account->id])
        ->assertRedirect('https://checkout.stripe.test/session');

    expect(Payment::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);
});

it('shows a reversed order as owed again, with the deadline its reversal carries', function (): void {
    [$registrationId, $order] = checkoutPageOrder();

    $order->forceFill(['status' => OrderStatus::PaymentReversed, 'paid_at' => now()->subWeek()])->save();

    $payment = Payment::factory()->create(['order_id' => $order->id]);

    // The newest final reversal owns the deadline (ADR-0057 point 4), the
    // same rule the console's `repaymentDeadline()` reads.
    PaymentReversal::factory()->create([
        'order_id' => $order->id,
        'payment_id' => $payment->id,
        'provider_reference' => 'chb_pay_old',
        'occurred_at' => now()->subDays(30),
        'repay_due_on' => now()->subDays(23)->toDateString(),
    ]);
    PaymentReversal::factory()->create([
        'order_id' => $order->id,
        'payment_id' => $payment->id,
        'provider_reference' => 'chb_pay_new',
        'occurred_at' => now()->subDay(),
        'repay_due_on' => '2026-09-14',
    ]);

    $this->get('/pay/registration/'.$registrationId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.status', 'payment_reversed')
            ->where('repayDueOn', '2026-09-14'));
});

/**
 * Whole-branch review finding 2. #131 gave a direct registration order the
 * carts' own sixty-minute payment window and a sweep that cancels it; #5
 * put pay links to that order in the confirmation and payment-failed mails
 * and on `/my/orders`. Nothing on the payer's side said the deadline
 * existed, and `start()` took money for an order the sweep was about to
 * cancel — money `RecordPaymentSuccess` then records as `PaymentUnapplied`
 * and an operator has to notice.
 */
it('states the payment window on the registration pay page (#131)', function (): void {
    $this->freezeTime();
    [$registrationId, $order] = checkoutPageOrder();

    $this->get('/pay/registration/'.$registrationId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payable', true)
            ->where('refusalReason', null)
            ->where('holdUntil', $order->created_at->copy()->addMinutes(Cart::CHECKOUT_GRACE_MINUTES)->toIso8601String()));
});

it('refuses a registration order past its payment window, on the page and at the POST (#131)', function (): void {
    $this->freezeTime();
    [$registrationId, $order, $account] = checkoutPageOrder();

    $order->forceFill(['created_at' => now()->subMinutes(Cart::CHECKOUT_GRACE_MINUTES + 1)])->save();

    $this->get('/pay/registration/'.$registrationId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payable', false)
            ->where('refusalReason', 'The payment window for this order has run out.'));

    // A sentence on the form, not an error page: this order is linked from
    // a mail, and a payer who arrives a minute late deserves to read why.
    $this->post('/pay/registration/'.$registrationId.'/start', ['account' => $account->id])
        ->assertRedirect()
        ->assertSessionHasErrors(['account' => 'The payment window for this order has run out.']);

    expect(Payment::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0);
});

it('leaves a bank-transfer brand\'s registration order payable however old it is (#131)', function (): void {
    $this->freezeTime();
    // No hosted account at all — the sweep never touches such an order
    // (`ExpireRegistrationOrders`, fix round 1), so the page must not claim
    // a deadline the brand never set.
    [$registrationId, $order] = checkoutPageOrder(stripeConnected: false);

    $order->forceFill(['created_at' => now()->subWeek()])->save();

    $this->get('/pay/registration/'.$registrationId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payable', true)
            ->where('holdUntil', null));
});

/**
 * Whole-branch review finding 7, review 6 parked nit A: `payableOrderFor()`
 * promised that "a registration order is sent to its own page instead of
 * being served here twice", and nothing did it — one order had two payable
 * URLs that refused it differently.
 */
it('sends a registration order addressed by order id to its own pay page (#5)', function (): void {
    [$registrationId, $order, $account, $payer] = checkoutPageOrder();

    // A direct registration order does carry a payer
    // (`CreateOrderOnSettlementRequested` passes the registrant), so the
    // buyer check passes and the page used to render.
    $order->forceFill(['payer_user_id' => $payer->getKey()])->save();

    $this->get('/pay/order/'.$order->getKey())
        ->assertRedirect(route('commerce.pay.show', ['registration' => $registrationId], absolute: false));

    $this->post('/pay/order/'.$order->getKey().'/start', ['account' => $account->id])
        ->assertRedirect(route('commerce.pay.show', ['registration' => $registrationId], absolute: false));

    expect(Payment::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0);
});
