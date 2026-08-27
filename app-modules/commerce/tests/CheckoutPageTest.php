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
}

/**
 * @return array{0: string, 1: Order}
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

    if ($stripeConnected) {
        SettlementAccount::factory()->create([
            'provider' => SettlementAccount::PROVIDER_STRIPE,
            'provider_account_id' => 'acct_page_1',
            // Only an active Stripe account is offered at checkout (ADR-0046).
            'capabilities' => ['card_payments' => 'active'],
        ]);
        app()->instance(StripeApi::class, new CheckoutPageTestFakeStripe);
    }

    return [$registrationId, $order];
}

it('shows the order summary for a registration ULID', function (): void {
    [$registrationId] = checkoutPageOrder();

    $this->get('/pay/registration/'.$registrationId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pay/Show')
            ->where('order.totalGross', '119.00')
            ->where('order.lines.0.label', 'Weekend ticket')
            ->where('hostedCheckoutAvailable', true)
            ->where('paymentStatus', null));
});

it('offers no online payment for a manual-only brand', function (): void {
    [$registrationId] = checkoutPageOrder(stripeConnected: false);

    $this->get('/pay/registration/'.$registrationId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hostedCheckoutAvailable', false));

    $this->post('/pay/registration/'.$registrationId.'/start')->assertStatus(409);
});

it('starts the hosted checkout and hard-redirects to the provider', function (): void {
    [$registrationId, $order] = checkoutPageOrder();

    // Inertia::location: a plain request gets a real redirect to the
    // provider (SPA requests would get 409 + X-Inertia-Location).
    $this->post('/pay/registration/'.$registrationId.'/start')
        ->assertRedirect('https://checkout.stripe.test/session');

    expect(Payment::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);
});

it('refuses to start a checkout for a settled order', function (): void {
    [$registrationId, $order] = checkoutPageOrder();

    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    $this->post('/pay/registration/'.$registrationId.'/start')->assertStatus(409);
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
