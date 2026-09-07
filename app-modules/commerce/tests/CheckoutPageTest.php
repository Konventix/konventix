<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
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

    public function createCheckoutSession(array $params, string $stripeAccountId): StripeCheckoutSession
    {
        $this->sessions[] = $params;

        return new StripeCheckoutSession(id: 'cs_page_'.count($this->sessions), url: 'https://checkout.stripe.test/session');
    }

    public function createRefund(array $params, string $stripeAccountId): void {}

    public function createConnectedAccount(string $displayName, string $contactEmail, string $country): string
    {
        return 'acct_x';
    }

    public function createOnboardingLink(string $stripeAccountId, string $returnUrl, string $refreshUrl): string
    {
        return 'https://stripe.test/link';
    }

    public function fetchAccount(string $stripeAccountId): array
    {
        return ['card_payments' => 'unknown'];
    }
}

/**
 * @return array{0: string, 1: Order, 2: ?SettlementAccount}
 */
function checkoutPageOrder(bool $stripeConnected = true): array
{
    test()->withBrand();

    $registrationId = (string) Str::ulid();

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
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
        $account = SettlementAccount::factory()->create([
            'provider' => SettlementAccount::PROVIDER_STRIPE,
            'provider_account_id' => 'acct_page_1',
            // Only an active Stripe account is offered at checkout (ADR-0046).
            'capabilities' => ['card_payments' => 'active'],
        ]);
        app()->instance(StripeApi::class, new CheckoutPageTestFakeStripe);
    }

    return [$registrationId, $order, $account];
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

it('404s an unknown registration', function (): void {
    checkoutPageOrder();

    $this->get('/pay/registration/'.Str::ulid())->assertNotFound();
});

it('pays the latest order when an amendment added a second one', function (): void {
    // ADR-0036: a registration maps to N orders; the payable one is newest.
    [$registrationId] = checkoutPageOrder();

    $delta = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
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
    [$registrationId, , $account] = checkoutPageOrder();
    checkoutPageLegalTexts();
    $payer = User::factory()->create();

    $this->actingAs($payer)
        ->post('/pay/registration/'.$registrationId.'/start', [
            'account' => $account->id,
            'accept_terms' => true,
        ])
        ->assertRedirect('https://checkout.stripe.test/session');

    $consents = Consent::query()->where('subject_id', $payer->getKey())->get();

    expect($consents)->toHaveCount(2)
        ->and($consents->pluck('capture_context')->unique()->all())->toBe(['checkout']);
});

/**
 * `/pay/registration/{registration}` deliberately carries no `auth`
 * middleware, so a guest can reach it. The declaration is still required —
 * the payer has to be shown the texts either way — but there is no subject
 * to record it against until Compliance issues an account-less subject
 * identity (see the Consent model's docblock). Recording it under a
 * fabricated id would be worse than not recording it.
 */
it('accepts a guest checkout that ticked the box and records no consent for it', function (): void {
    [$registrationId, $order, $account] = checkoutPageOrder();
    checkoutPageLegalTexts();

    $this->post('/pay/registration/'.$registrationId.'/start', [
        'account' => $account->id,
        'accept_terms' => true,
    ])->assertRedirect('https://checkout.stripe.test/session');

    expect(Consent::query()->count())->toBe(0)
        ->and(Payment::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);
});
