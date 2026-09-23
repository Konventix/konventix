<?php

declare(strict_types=1);

use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * Helpers that more than one file of this module's suite calls. Pest loads
 * every module's tests/Helpers.php from tests/Pest.php, so they exist in
 * every worker of a parallel run — a function declared inside a *Test.php
 * file exists only in the process that happened to load that file, and the
 * names stay process-global across all modules (.ai/rules/tests.md).
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
 * checkout, direct-Action calls included. Address fields stay optional
 * (#4): most tests exercise a cart well under the small-amount threshold and
 * pass none at all, exactly as an ordinary shop checkout does.
 */
function cartCheckoutBillingParty(
    User $user,
    ?string $addressLine = null,
    ?string $postalCode = null,
    ?string $city = null,
    ?string $countryCode = null,
): BillingPartyData {
    return BillingPartyData::ofAccount($user->name, $user->email, $addressLine, $postalCode, $city, $countryCode);
}

/**
 * The return URL `CartController::checkout()` hands `PlaceOrderFromCart`
 * (bugfix round 2026-09-21, Task 3): a closure over the placed order, since
 * the order does not exist when the call is made. The controller's own
 * closure resolves `commerce.mine.show`; `CartCheckoutTest` pins that
 * through the controller, and every direct Action call uses this one.
 *
 * @return Closure(Order): string
 */
function cartCheckoutReturnUrl(): Closure
{
    return fn (Order $order): string => 'https://brand.test/my/orders/'.$order->getKey();
}

function orderMailsRecipient(string $brandId, ?string $locale = null): User
{
    $user = User::factory()->create();

    // Messaging's recipient read model has no factory of its own — rows
    // exist only through `ProjectRecipient`'s projection, so a test that
    // needs `SendMessage` to resolve an address emits the Identity event
    // instead (`Recipient`'s own docblock).
    event(new UserRegistered(
        userId: $user->id,
        brandId: $brandId,
        email: $user->email,
        displayName: $user->name,
        username: $user->username,
        locale: $locale ?? $user->locale,
        registeredAt: now()->toIso8601String(),
    ));

    return $user;
}

function paidInvoiceBrand(): Brand
{
    $brand = test()->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

function productOf(Brand $brand, array $attributes = []): Product
{
    $product = Product::factory()->make($attributes);
    $product->brand_id = $brand->getKey();
    $product->save();

    return $product;
}
