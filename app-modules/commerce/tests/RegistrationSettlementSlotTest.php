<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Identity\Models\User;
use Konventix\Registration\Actions\ApproveRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * What a registration cost, shown on the page its registrant already owns.
 *
 * The direction is the whole point: Commerce cannot resolve "whose orders
 * are these" — an order is keyed by `settles_type`/`settles_id` and reading
 * Registration's tables is forbidden (ADR-0004). Asked about one
 * registration whose ownership Registration has already checked, it can
 * answer completely, and the signed download link carries that decision to
 * a request Commerce could not have authorized on its own.
 */
function settlementProps(AssertableInertia $page): ?array
{
    $slot = collect($page->toArray()['props']['slots'])
        ->firstWhere('component', 'commerce/RegistrationSettlement');

    return $slot === null ? null : $slot['props']['settlement'];
}

it('shows the order and a pay link while it is unpaid', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    Order::factory()->settles('registration', $registration->getKey())->create([
        'total_gross' => '35.70',
        'currency' => 'EUR',
    ]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($registration): void {
            $settlement = settlementProps($page);

            expect($settlement['status'])->toBe('pending')
                ->and($settlement['totalGross'])->toBe('35.70')
                ->and($settlement['payUrl'])->toBe('/pay/registration/'.$registration->getKey());
        });
});

it('offers no pay link once the order is paid', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    Order::factory()->settles('registration', $registration->getKey())->paid()->create();

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(fn (AssertableInertia $page) => expect(settlementProps($page)['payUrl'])->toBeNull());
});

/**
 * Free settlement creates no order at all (ADR-0026), so this is the common
 * answer rather than a missing one — and the component renders nothing
 * rather than putting a money heading on a page about a free ticket.
 */
it('reports nothing to settle for a free registration', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(settlementProps($page))->toBeNull());
});

it('offers the archived invoice, and downloads the sealed copy', function (): void {
    $this->withBrand();

    Storage::fake('invoices');
    Storage::disk('invoices')->put('archive/inv.pdf', '%PDF-1.4 sealed');

    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    $order = Order::factory()->settles('registration', $registration->getKey())->paid()->create();

    $invoice = Invoice::factory()->create([
        'order_id' => $order->getKey(),
        'invoice_number' => 'KVX-2026-00042',
        'document_path' => 'archive/inv.pdf',
        'document_disk' => 'invoices',
    ]);

    $url = null;

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(function (AssertableInertia $page) use (&$url, $invoice): void {
            $settlement = settlementProps($page);

            expect($settlement['invoiceNumber'])->toBe('KVX-2026-00042');

            $url = $settlement['documentUrl'];

            expect($url)->toContain($invoice->getKey())
                ->and($url)->toContain('signature=');
        });

    $this->actingAs($user)->get($url)->assertOk();
});

it('refuses the invoice download without the signature', function (): void {
    $this->withBrand();

    Storage::fake('invoices');
    Storage::disk('invoices')->put('archive/inv.pdf', '%PDF-1.4 sealed');

    $user = User::factory()->create();
    $invoice = Invoice::factory()->create([
        'document_path' => 'archive/inv.pdf',
        'document_disk' => 'invoices',
    ]);

    $this->actingAs($user)
        ->get('/my/invoices/'.$invoice->getKey().'/document')
        ->assertForbidden();
});

it('offers no invoice link before the document has been generated', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    $order = Order::factory()->settles('registration', $registration->getKey())->create();

    Invoice::factory()->create([
        'order_id' => $order->getKey(),
        'document_path' => null,
        'document_disk' => null,
    ]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(fn (AssertableInertia $page) => expect(settlementProps($page)['documentUrl'])->toBeNull());
});

/**
 * A4 (#210): with `commerce:shop` on, an `instant` registration settles into
 * a cart line rather than an order (ADR-0047 point 3) — so "no order" is no
 * longer the same answer as "nothing to pay". The slot answers the cart
 * side too, or the registrant's own page shows nothing at all while money
 * is owed.
 */
function cartSettlementProps(AssertableInertia $page): ?array
{
    $slot = collect($page->toArray()['props']['slots'])
        ->firstWhere('component', 'commerce/RegistrationSettlement');

    return $slot === null ? null : $slot['props']['cart'];
}

it('shows an open cart line as payable in the cart', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    $cart = Cart::factory()->forPayer($user->getKey())->create();
    CartLine::factory()->forCart($cart)->registration()->create([
        'ref_id' => $registration->getKey(),
        'unit_amount_net' => '30.00',
    ]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $cartSettlement = cartSettlementProps($page);

            expect($cartSettlement['status'])->toBe('in_cart')
                ->and($cartSettlement['cartUrl'])->toBe('/cart')
                ->and($cartSettlement['totalGross'])->toBe('35.70')
                ->and($cartSettlement['expiresAt'])->not->toBeNull()
                ->and(settlementProps($page))->toBeNull();
        });
});

it('says the reservation lapsed once the cart expired', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    $cart = Cart::factory()->forPayer($user->getKey())->expired()->create();
    CartLine::factory()->forCart($cart)->registration()->create(['ref_id' => $registration->getKey()]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $cartSettlement = cartSettlementProps($page);

            expect($cartSettlement['status'])->toBe('expired')
                ->and($cartSettlement['cartUrl'])->toBeNull();
        });
});

it('says the reservation lapsed while the sweep has not caught up with it yet', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    $cart = Cart::factory()->forPayer($user->getKey())->pastDue()->create();
    CartLine::factory()->forCart($cart)->registration()->create(['ref_id' => $registration->getKey()]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(fn (AssertableInertia $page) => expect(cartSettlementProps($page)['status'])->toBe('expired'));
});

it('points a checked-out cart line at the payer\'s orders', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    $cart = Cart::factory()->forPayer($user->getKey())->create();
    CartLine::factory()->forCart($cart)->registration()->create(['ref_id' => $registration->getKey()]);
    $cart->forceFill([
        'status' => CartStatus::CheckedOut,
        'checked_out_order_id' => Order::factory()->create(['settles_type' => 'cart', 'settles_id' => $cart->getKey()])->getKey(),
    ])->save();

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(function (AssertableInertia $page): void {
            $cartSettlement = cartSettlementProps($page);

            expect($cartSettlement['status'])->toBe('checkout_started')
                ->and($cartSettlement['ordersUrl'])->toBe('/my/orders')
                ->and($cartSettlement['cartUrl'])->toBeNull();
        });
});

it('reports no cart settlement when a direct order settles the registration', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    Order::factory()->settles('registration', $registration->getKey())->create();

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(fn (AssertableInertia $page) => expect(cartSettlementProps($page))->toBeNull());
});

/**
 * ADR-0057 point 3, GitLab #197: a reversal revives the receivable, so the
 * registrant's own page owes the same pay link `/my/orders` and the mails
 * already carry — through `Settlement\PayLink`, so the four surfaces cannot
 * name different places — and the deadline the money is owed back by
 * (point 4), which the slot had no field for at all.
 */
it('shows the pay link and the repayment deadline once a payment was reversed', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    $order = Order::factory()->settles('registration', $registration->getKey())->paid()->create();
    $order->forceFill(['status' => OrderStatus::PaymentReversed])->save();

    $payment = Payment::factory()->create(['order_id' => $order->getKey(), 'status' => PaymentStatus::Reversed]);
    PaymentReversal::factory()->create([
        'payment_id' => $payment->getKey(),
        'order_id' => $order->getKey(),
        'status' => ReversalStatus::Final,
        'occurred_at' => now()->subDay(),
        'repay_due_on' => now()->addDays(6)->toDateString(),
    ]);

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($registration): void {
            $settlement = settlementProps($page);

            expect($settlement['status'])->toBe('payment_reversed')
                ->and($settlement['payUrl'])->toBe('/pay/registration/'.$registration->getKey())
                ->and($settlement['repayDueOn'])->toBe(now()->addDays(6)->toDateString());
        });
});

/**
 * The deadline is the reversal's, not a field every order carries: a
 * pending order owes nothing back and a date beside it would invent one.
 */
it('names no repayment deadline on an order that was never reversed', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $registration = Registration::factory()->create(['registrant_user_id' => $user->getKey()]);

    Order::factory()->settles('registration', $registration->getKey())->create();

    $this->actingAs($user)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertInertia(fn (AssertableInertia $page) => expect(settlementProps($page)['repayDueOn'])->toBeNull());
});

/**
 * Branch review finding 10, filed as the invariant it rests on.
 *
 * This slot is authorized by Registration on `registrations.registrant_user_id`
 * and then mints a signed invoice link and a pay link, while the pay pages
 * authorize on `orders.payer_user_id`. The two identities coincide because
 * `CreateOrderOnSettlementRequested` writes the settlement request's
 * `registrantUserId` onto the order (ADR-0044) and
 * `PlaceRegistrationLineInCart` opens the cart as
 * `OpenCart::for($brandId, $registrantUserId)` — an invariant that lived in
 * two other files and nothing asserted. Adding a payer check to the slot
 * instead would hide the block on every order with a null payer (booked by
 * hand, or placed before ADR-0044), so the invariant is what is pinned.
 */
it('settles a registration onto an order whose payer is the registrant, which is what lets this slot skip a payer check', function (): void {
    $this->withBrand();

    $registrant = User::factory()->create();
    $type = RegistrationType::factory()->invoiceSettled()->create();

    $registration = app(SubmitRegistration::class)->handle($type, $registrant->getKey(), new SubmitRegistrationData(
        organizationName: null,
        billingRecipientName: 'Erika Musterfrau',
        billingEmail: 'rechnung@musterfirma.example',
        billingAddressLine: 'Musterstraße 1',
        billingPostalCode: '18439',
        billingCity: 'Stralsund',
        billingCountryCode: 'DE',
        billingVatId: null,
    ));
    app(ApproveRegistration::class)->handle($registration, (string) Str::ulid());

    $order = Order::query()->where('settles_id', $registration->getKey())->sole();

    expect($order->payer_user_id)
        ->toBe($registration->registrant_user_id)
        ->and($order->payer_user_id)->toBe($registrant->getKey());

    // …and the page the slot renders on is the same person's.
    $this->actingAs($registrant)
        ->get('/my/registrations/'.$registration->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(settlementProps($page)['orderId'])->toBe($order->getKey()));
});
