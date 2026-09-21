<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Commerce\Actions\AddProductToCart;
use Konventix\Commerce\Actions\OpenCart;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * The checkout half of the contract-consent gate (GitLab #205, operator
 * decision of 08.09.2026: contract texts are asked for at login and at
 * checkout).
 *
 * Commerce learns nothing about policies here: Compliance's existing
 * `checkout.consent` slot contribution answers with a URL to send the payer
 * to, the same way it already answers whether a declaration is required at
 * all. What blocks is only `LawfulBasis::Contract` — the inline
 * `accept_terms` box keeps recording `terms` and `withdrawal` exactly as it
 * did since !20.
 *
 * Helper names prefixed `consentGate*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
final class ConsentGateTestFakeStripe implements StripeApi
{
    /** @var list<array<string, mixed>> */
    public array $sessions = [];

    public function createCheckoutSession(array $params): StripeCheckoutSession
    {
        $this->sessions[] = $params;

        return new StripeCheckoutSession(id: 'cs_gate_'.count($this->sessions), url: 'https://checkout.stripe.test/gate-session');
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

function consentGateBrand(): Brand
{
    $brand = test()->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();
    test()->activateFeature('commerce:shop', $brand);

    return $brand;
}

function consentGateAccount(): SettlementAccount
{
    config()->set('services.stripe.secret', 'sk_test_gate');
    config()->set('services.stripe.webhook_secret', 'whsec_gate');

    $account = SettlementAccount::factory()->create(['provider' => SettlementAccount::PROVIDER_STRIPE]);

    app()->instance(StripeApi::class, new ConsentGateTestFakeStripe);

    return $account;
}

/**
 * The purchase texts, with the AGB as the contract itself and the
 * Widerrufsbelehrung beside it — the shape `CheckoutLegalTexts` resolves.
 */
function consentGateLegalTexts(): PolicyVersion
{
    $withdrawal = Policy::factory()->operatorWide()->withdrawal()->create();
    PolicyVersion::factory()->for($withdrawal)->operatorWide()->published()->create([
        'version' => 'withdrawal-v1',
        'requires_consent' => false,
        'lawful_basis' => LawfulBasis::Consent,
    ]);

    $terms = Policy::factory()->operatorWide()->terms()->create();

    return PolicyVersion::factory()->for($terms)->operatorWide()->published()->create([
        'version' => 'terms-v1',
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Contract,
    ]);
}

function consentGateCart(Brand $brand, string $payerUserId): Cart
{
    $product = Product::factory()->published()->create(['unit_amount_net' => '10.00']);
    $variant = ProductVariant::factory()->for($product, 'product')->withCapacity(10)->create();
    $cart = OpenCart::for($brand->getKey(), $payerUserId);
    app(AddProductToCart::class)->handle($cart, $variant, 1);

    return $cart->fresh();
}

function consentGateRegistrationOrder(string $payerUserId): string
{
    $registrationId = (string) Str::ulid();

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
        // GitLab #185: the pay page serves the order's own payer only, so
        // the order the gate is exercised on has to name them.
        'payer_user_id' => $payerUserId,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    OrderLine::factory()->create(['order_id' => $order->id, 'label' => 'Weekend ticket', 'line_gross' => '119.00']);

    return $registrationId;
}

it('sends a cart checkout with an outstanding contract text to the consent screen, and places no order', function (): void {
    $brand = consentGateBrand();
    consentGateLegalTexts();
    $user = User::factory()->create();
    consentGateCart($brand, $user->getKey());
    $account = consentGateAccount();

    test()->actingAs($user)
        ->post('/cart/checkout', ['account' => $account->id, 'accept_terms' => true])
        ->assertRedirect(route('policies.required', absolute: false))
        // The page the payer was on, not the POST they sent: agreeing has to
        // land them back on the confirmation step they were confirming from
        // (ADR-0066 §6.3), and a consent screen may not replay a POST.
        ->assertSessionHas('url.intended', route('commerce.cart.confirm', absolute: false));

    expect(Order::query()->count())->toBe(0)
        ->and(Consent::query()->count())->toBe(0);
});

it('lets a cart checkout through once the contract text is agreed', function (): void {
    $brand = consentGateBrand();
    $terms = consentGateLegalTexts();
    $user = User::factory()->create();
    consentGateCart($brand, $user->getKey());
    $account = consentGateAccount();

    Consent::factory()->for($terms, 'policyVersion')->create(['subject_id' => $user->getKey()]);

    // ADR-0066 D-CPL-07: the provider is chosen on the cart and the order
    // is placed from the confirmation step.
    test()->actingAs($user)->post('/cart/choose', ['account' => $account->id]);

    test()->actingAs($user)
        ->post('/cart/checkout', ['account' => $account->id, 'accept_terms' => true])
        ->assertRedirect('https://checkout.stripe.test/gate-session');

    expect(Order::query()->count())->toBe(1);
});

it('sends a payment start with an outstanding contract text to the consent screen, and starts no payment', function (): void {
    consentGateBrand();
    consentGateLegalTexts();
    $user = User::factory()->create();
    $registrationId = consentGateRegistrationOrder($user->getKey());
    $account = consentGateAccount();

    test()->actingAs($user)
        ->post('/pay/registration/'.$registrationId.'/start', ['account' => $account->id, 'accept_terms' => true])
        ->assertRedirect(route('policies.required', absolute: false))
        ->assertSessionHas('url.intended', route('commerce.pay.confirm', ['registration' => $registrationId], absolute: false));

    expect(Payment::query()->count())->toBe(0)
        ->and(Consent::query()->count())->toBe(0);
});

it('lets a payment start through once the contract text is agreed', function (): void {
    consentGateBrand();
    $terms = consentGateLegalTexts();
    $user = User::factory()->create();
    $registrationId = consentGateRegistrationOrder($user->getKey());
    $account = consentGateAccount();

    Consent::factory()->for($terms, 'policyVersion')->create(['subject_id' => $user->getKey()]);

    test()->actingAs($user)
        ->post('/pay/registration/'.$registrationId.'/start', ['account' => $account->id, 'accept_terms' => true])
        ->assertRedirect('https://checkout.stripe.test/gate-session');

    expect(Payment::query()->count())->toBe(1);
});

it('keeps recording terms and withdrawal from the inline box on the checkout that goes through', function (): void {
    $brand = consentGateBrand();
    $terms = consentGateLegalTexts();
    $user = User::factory()->create();
    consentGateCart($brand, $user->getKey());
    $account = consentGateAccount();

    Consent::factory()->for($terms, 'policyVersion')->create([
        'subject_id' => $user->getKey(),
        'capture_context' => 'consent_form',
    ]);

    // ADR-0066 D-CPL-07: the provider is chosen on the cart and the order
    // is placed from the confirmation step.
    test()->actingAs($user)->post('/cart/choose', ['account' => $account->id]);

    test()->actingAs($user)
        ->post('/cart/checkout', ['account' => $account->id, 'accept_terms' => true])
        ->assertRedirect('https://checkout.stripe.test/gate-session');

    $versions = Consent::query()->where('subject_id', $user->getKey())->pluck('policy_version_id');

    $names = PolicyVersion::withoutBrandScope()->whereIn('id', $versions)->pluck('version')->sort()->values()->all();

    // The AGB row is the one the consent screen already wrote (`GrantConsent`
    // is idempotent per subject and live version, so the checkout does not
    // duplicate it); the Widerrufsbelehrung is the one the inline box adds.
    expect($names)->toBe(['terms-v1', 'withdrawal-v1'])
        ->and(Consent::query()->where('subject_id', $user->getKey())->where('capture_context', 'checkout')->count())->toBe(1);
});

it('does not block a checkout over a consent-based required text', function (): void {
    $brand = consentGateBrand();
    $user = User::factory()->create();
    consentGateCart($brand, $user->getKey());
    $account = consentGateAccount();

    $policy = Policy::factory()->operatorWide()->terms()->create();
    PolicyVersion::factory()->for($policy)->operatorWide()->published()->create([
        'version' => 'terms-v1',
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Consent,
    ]);

    // ADR-0066 D-CPL-07: the provider is chosen on the cart and the order
    // is placed from the confirmation step.
    test()->actingAs($user)->post('/cart/choose', ['account' => $account->id]);

    test()->actingAs($user)
        ->post('/cart/checkout', ['account' => $account->id, 'accept_terms' => true])
        ->assertRedirect('https://checkout.stripe.test/gate-session');
});

/**
 * Review 6 finding 5: the payer is not made to pick an account and tick a
 * box only to be bounced by the POST. Every page that renders the control
 * redirects on GET while the agreement is outstanding, and the intent it
 * remembers is the page itself.
 */
it('sends the payer to the consent screen before the cart form is shown', function (): void {
    $brand = consentGateBrand();
    consentGateLegalTexts();
    $user = User::factory()->create();
    consentGateCart($brand, $user->getKey());
    consentGateAccount();

    test()->actingAs($user)
        ->get('/cart')
        ->assertRedirect(route('policies.required', absolute: false))
        ->assertSessionHas('url.intended', route('commerce.cart.show', absolute: false));
});

it('sends the payer to the consent screen before the registration pay form is shown', function (): void {
    consentGateBrand();
    consentGateLegalTexts();
    $user = User::factory()->create();
    $registrationId = consentGateRegistrationOrder($user->getKey());
    consentGateAccount();

    test()->actingAs($user)
        ->get('/pay/registration/'.$registrationId)
        ->assertRedirect(route('policies.required', absolute: false))
        ->assertSessionHas('url.intended', route('commerce.pay.show', ['registration' => $registrationId], absolute: false));
});

/**
 * Review 6 nit 11: agreeing at the consent screen has to clear the checkout
 * gate in the same session. The inline `accept_terms` box still appears —
 * that is the order-level declaration §312j BGB asks for at the order
 * button (#2, ADR-0048), a different act from agreeing to a republished
 * version — but the *gate* must not fire twice, and a payer who consented a
 * moment ago must reach the checkout.
 */
it('lets a payer who just agreed at the consent screen straight through the checkout', function (): void {
    $brand = consentGateBrand();
    $terms = consentGateLegalTexts();
    $user = User::factory()->create();
    consentGateCart($brand, $user->getKey());
    $account = consentGateAccount();

    test()->actingAs($user)
        ->get('/cart')
        ->assertRedirect(route('policies.required', absolute: false));

    test()->actingAs($user)
        ->post('/consents', ['versionIds' => [$terms->getKey()]])
        ->assertRedirect(route('commerce.cart.show', absolute: false));

    test()->actingAs($user)->get('/cart')->assertOk();

    // ADR-0066 D-CPL-07: the provider is chosen on the cart and the order
    // is placed from the confirmation step.
    test()->actingAs($user)->post('/cart/choose', ['account' => $account->getKey()]);

    test()->actingAs($user)
        ->post('/cart/checkout', ['account' => $account->getKey(), 'accept_terms' => true])
        ->assertRedirect('https://checkout.stripe.test/gate-session');

    expect(Order::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The two-step checkout (ADR-0066 §6.3, D-CPL-07)
|--------------------------------------------------------------------------
|
| Which texts apply may depend on the settlement provider, so the provider is
| chosen first and the texts are shown after. A payer may not be held to have
| agreed to a list they never saw — which is what makes going back clear the
| acceptance, and what makes `RecordCheckoutConsent` resolve the context the
| confirmation step rendered rather than re-deriving one from the brand.
|
*/

/**
 * A second offered account, so the brand has a choice to make and step one
 * is a real page rather than a redirect.
 */
function consentGateSecondAccount(): SettlementAccount
{
    return SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_NULL,
    ]);
}

it('names no legal text on step one of the registration checkout', function (): void {
    consentGateBrand();
    consentGateLegalTexts();
    $user = User::factory()->create();
    $registrationId = consentGateRegistrationOrder($user->getKey());
    $account = consentGateAccount();
    consentGateSecondAccount();

    // The contract text is outstanding for this payer, so settle it first —
    // the #205 gate is not what this case is about.
    Consent::factory()->for(PolicyVersion::withoutBrandScope()->where('version', 'terms-v1')->sole(), 'policyVersion')
        ->create(['subject_id' => $user->getKey()]);

    test()->actingAs($user)
        ->get('/pay/registration/'.$registrationId)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('pay/Show')
            ->has('accounts', 2)
            // Nothing legal at all: the list depends on the choice this page
            // is asking for.
            ->missing('consentSlots'));

    test()->actingAs($user)
        ->post('/pay/registration/'.$registrationId.'/choose', ['account' => $account->getKey()])
        ->assertRedirect('/pay/registration/'.$registrationId.'/confirm');

    test()->actingAs($user)
        ->get('/pay/registration/'.$registrationId.'/confirm')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('pay/Confirm')
            ->where('account.id', $account->getKey())
            ->where('singleAccount', false)
            ->where('backUrl', '/pay/registration/'.$registrationId)
            ->where('consentSlots.0.props.required', true)
            ->where('consentSlots.0.props.policies.0.slug', 'terms')
            // `requires_consent: false` on the withdrawal text, so it is
            // read rather than agreed to (D-CPL-08).
            ->where('consentSlots.0.props.notices.0.slug', 'withdrawal'));
});

it('sends a payer with no provider on record back to step one', function (): void {
    consentGateBrand();
    consentGateLegalTexts();
    $user = User::factory()->create();
    $registrationId = consentGateRegistrationOrder($user->getKey());
    consentGateAccount();
    consentGateSecondAccount();

    Consent::factory()->for(PolicyVersion::withoutBrandScope()->where('version', 'terms-v1')->sole(), 'policyVersion')
        ->create(['subject_id' => $user->getKey()]);

    test()->actingAs($user)
        ->get('/pay/registration/'.$registrationId.'/confirm')
        ->assertRedirect('/pay/registration/'.$registrationId);
});

it('clears the recorded provider when the payer goes back to step one', function (): void {
    consentGateBrand();
    consentGateLegalTexts();
    $user = User::factory()->create();
    $registrationId = consentGateRegistrationOrder($user->getKey());
    $stripe = consentGateAccount();
    consentGateSecondAccount();

    Consent::factory()->for(PolicyVersion::withoutBrandScope()->where('version', 'terms-v1')->sole(), 'policyVersion')
        ->create(['subject_id' => $user->getKey()]);

    test()->actingAs($user)->post('/pay/registration/'.$registrationId.'/choose', ['account' => $stripe->getKey()]);
    test()->actingAs($user)->get('/pay/registration/'.$registrationId.'/confirm')->assertOk();

    // The *Back* link. Landing on step one un-makes the choice, and with it
    // any acceptance the confirmation step had collected against it — a new
    // choice always re-resolves the list.
    test()->actingAs($user)->get('/pay/registration/'.$registrationId)->assertOk();

    test()->actingAs($user)
        ->get('/pay/registration/'.$registrationId.'/confirm')
        ->assertRedirect('/pay/registration/'.$registrationId);

    // And a POST that names a provider nobody chose is refused rather than
    // taken as a declaration.
    test()->actingAs($user)
        ->post('/pay/registration/'.$registrationId.'/start', [
            'account' => $stripe->getKey(),
            'accept_terms' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('account');

    expect(Payment::query()->count())->toBe(0);
});

it('refuses a start that names a provider other than the one on record', function (): void {
    consentGateBrand();
    consentGateLegalTexts();
    $user = User::factory()->create();
    $registrationId = consentGateRegistrationOrder($user->getKey());
    $stripe = consentGateAccount();
    $other = consentGateSecondAccount();

    Consent::factory()->for(PolicyVersion::withoutBrandScope()->where('version', 'terms-v1')->sole(), 'policyVersion')
        ->create(['subject_id' => $user->getKey()]);

    test()->actingAs($user)->post('/pay/registration/'.$registrationId.'/choose', ['account' => $stripe->getKey()]);

    test()->actingAs($user)
        ->post('/pay/registration/'.$registrationId.'/start', [
            'account' => $other->getKey(),
            'accept_terms' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('account');

    expect(Payment::query()->count())->toBe(0);
});

it('lands a one-account brand on the confirmation step directly', function (): void {
    consentGateBrand();
    consentGateLegalTexts();
    $user = User::factory()->create();
    $registrationId = consentGateRegistrationOrder($user->getKey());
    $account = consentGateAccount();

    Consent::factory()->for(PolicyVersion::withoutBrandScope()->where('version', 'terms-v1')->sole(), 'policyVersion')
        ->create(['subject_id' => $user->getKey()]);

    test()->actingAs($user)
        ->get('/pay/registration/'.$registrationId)
        ->assertRedirect('/pay/registration/'.$registrationId.'/confirm');

    test()->actingAs($user)
        ->get('/pay/registration/'.$registrationId.'/confirm')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('account.id', $account->getKey())
            ->where('singleAccount', true)
            // Nothing to go back to: there was no choice.
            ->where('backUrl', null));
});

it('records the versions the confirmation step rendered, with the chosen provider in the snapshot', function (): void {
    $brand = consentGateBrand();
    consentGateLegalTexts();
    $user = User::factory()->create();
    $registrationId = consentGateRegistrationOrder($user->getKey());
    $account = consentGateAccount();

    Consent::factory()->for(PolicyVersion::withoutBrandScope()->where('version', 'terms-v1')->sole(), 'policyVersion')
        ->create(['subject_id' => $user->getKey()]);

    test()->actingAs($user)->get('/pay/registration/'.$registrationId.'/confirm')->assertOk();

    test()->actingAs($user)
        ->post('/pay/registration/'.$registrationId.'/start', [
            'account' => $account->getKey(),
            'accept_terms' => true,
        ])
        ->assertRedirect('https://checkout.stripe.test/gate-session');

    $consents = Consent::query()->where('subject_id', $user->getKey())->get();

    // Two texts applied to this purchase and two rows exist: the
    // pre-existing agreement to `terms-v1` is idempotent, and `withdrawal`
    // is recorded now.
    expect($consents)->toHaveCount(2)
        ->and($consents->pluck('brand_id')->unique()->all())->toBe([$brand->getKey()]);

    $withdrawal = $consents->first(fn (Consent $consent): bool => $consent->binding_context !== null);

    // D-CPL-09: the snapshot names the provider the payer chose in step one,
    // which is what makes "why was this person asked, and for which
    // purchase" one row rather than a reconstruction.
    expect($withdrawal->binding_context['settlement_provider'])->toBe('stripe')
        ->and($withdrawal->binding_context['order_id'])->not->toBeNull();
});
