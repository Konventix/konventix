<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Laravel\Pennant\Feature;

/**
 * `/my/orders/{order}` — one order on its own page (bugfix round
 * 2026-09-21, Task 3), and the page every hosted checkout returns to,
 * whichever way it ended. Every adapter takes one URL for success, cancel
 * and failure (`ProvidesHostedCheckout`), so the page branches on the
 * order's state: "Payment received" once paid, "Waiting for the payment
 * confirmation" while the attempt is still pending, "Pay now" while the
 * order is payable — which is what keeps the cancel path working.
 *
 * Ownership as on `/pay/order/{order}` (#185): the order's own payer, or
 * 404. Helper names prefixed `myOrderDetail*` — Pest helpers are
 * process-global (.ai/rules/tests.md).
 */
function myOrderDetailCartOrder(User $payer, string $registrationId): Order
{
    $order = Order::factory()->forPayer($payer->getKey())->create([
        'settles_type' => 'cart',
        'settles_id' => (string) Str::ulid(),
        'total_net' => '70.00',
        'total_tax' => '13.30',
        'total_gross' => '83.30',
        'billing_party' => BillingPartyData::ofAccount($payer->name, $payer->email, 'Hauptstraße 1', '10115', 'Berlin', 'DE'),
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'label' => 'Event T-Shirt',
        'quantity' => 2,
        'line_gross' => '23.80',
        'settles_kind' => 'product',
        'settles_ref' => (string) Str::ulid(),
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'position' => 2,
        'label' => 'Weekend Ticket',
        'quantity' => 1,
        'line_gross' => '59.50',
        'settles_kind' => 'registration',
        'settles_ref' => $registrationId,
    ]);

    return $order->fresh();
}

it('renders the order with its lines, totals, billing party, invoice and credit notes', function (): void {
    $this->withBrand();
    Storage::fake('invoices');
    $me = User::factory()->create();
    $registrationId = (string) Str::ulid();
    $order = myOrderDetailCartOrder($me, $registrationId);
    $order->forceFill(['status' => OrderStatus::Refunded, 'paid_at' => now()->subDay()])->save();

    $invoice = Invoice::factory()->create([
        'order_id' => $order->getKey(),
        'invoice_number' => 'KVX-2026-00042',
        'paid_at' => now()->subDay(),
        'document_path' => 'archive/inv.pdf',
        'document_disk' => 'invoices',
    ]);
    $creditNote = CreditNote::factory()->create([
        'order_id' => $order->getKey(),
        'invoice_id' => $invoice->getKey(),
        'billing_party' => $invoice->billing_party,
        'seller' => $invoice->seller,
        'credit_note_number' => 'GS-KVX-2026-00007',
        'document_path' => 'archive/gs.pdf',
        'document_disk' => 'invoices',
    ]);

    $this->actingAs($me)
        ->get('/my/orders/'.$order->getKey())
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($order, $invoice, $creditNote, $registrationId): void {
            $page->component('commerce/my/Show')
                ->where('order.id', $order->getKey())
                ->where('order.status', 'refunded')
                ->where('order.totalNet', '70.00')
                ->where('order.totalTax', '13.30')
                ->where('order.totalGross', '83.30')
                ->where('order.invoiceNumber', 'KVX-2026-00042')
                ->where('order.invoicePaid', true)
                ->where('order.payUrl', null)
                ->where('order.billingParty.city', 'Berlin')
                ->has('order.lines', 2)
                ->where('order.lines.1.registrationUrl', '/my/registrations/'.$registrationId)
                ->has('order.creditNotes', 1)
                ->where('order.creditNotes.0.number', 'GS-KVX-2026-00007');

            $row = $page->toArray()['props']['order'];

            expect($row['documentUrl'])->toContain($invoice->getKey())
                ->and($row['documentUrl'])->toContain('signature=')
                ->and($row['creditNotes'][0]['documentUrl'])->toContain($creditNote->getKey())
                ->and($row['creditNotes'][0]['documentUrl'])->toContain('signature=');
        });
});

it('404s for anyone but the order\'s own payer, and for an order that names none', function (): void {
    $this->withBrand();
    $payer = User::factory()->create();
    $order = myOrderDetailCartOrder($payer, (string) Str::ulid());

    $this->actingAs(User::factory()->create())
        ->get('/my/orders/'.$order->getKey())
        ->assertNotFound();

    // A manual booking, or an order placed before ADR-0044, belongs to
    // nobody: the payer itself is refused once the column is cleared.
    $order->forceFill(['payer_user_id' => null])->save();

    $this->actingAs($payer)
        ->get('/my/orders/'.$order->getKey())
        ->assertNotFound();
});

it('404s another brand\'s order even for the payer who placed it', function (): void {
    // Placed with another brand, requested on this host: the brand scope
    // (ADR-0010) narrows before the payer is compared — same shape as the
    // list's own cross-brand case in `MyOrdersTest`.
    $brand = $this->withBrand();
    $me = User::factory()->create();

    $this->withBrand(Brand::factory()->create());
    $elsewhere = myOrderDetailCartOrder($me, (string) Str::ulid());
    $this->withBrand($brand);

    $this->actingAs($me)
        ->get('/my/orders/'.$elsewhere->getKey())
        ->assertNotFound();
});

it('sends a guest to the login page', function (): void {
    $this->withBrand();

    $this->get('/my/orders/'.(string) Str::ulid())->assertRedirect('/login');
});

it('states the payment as received on a paid order', function (): void {
    $this->withBrand();
    $me = User::factory()->create();
    $order = myOrderDetailCartOrder($me, (string) Str::ulid());
    Payment::factory()->succeeded()->create(['order_id' => $order->getKey()]);
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    $this->actingAs($me)
        ->get('/my/orders/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.status', 'paid')
            ->where('order.paymentStatus', 'succeeded')
            ->where('order.paidAt', $order->fresh()->paid_at->toIso8601String())
            ->where('order.payUrl', null));
});

it('says it is waiting for the provider while the order is pending with a pending attempt', function (): void {
    // Back from the provider before the webhook landed — or back from a
    // cancelled session, which the provider never reports. Either way the
    // page states the pending attempt and still offers the way to pay.
    $this->withBrand();
    $me = User::factory()->create();
    $order = myOrderDetailCartOrder($me, (string) Str::ulid());
    Payment::factory()->create(['order_id' => $order->getKey(), 'status' => PaymentStatus::Pending]);

    $this->actingAs($me)
        ->get('/my/orders/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.status', 'pending')
            ->where('order.paymentStatus', 'pending')
            ->where('order.paidAt', null)
            ->where('order.payUrl', '/pay/order/'.$order->getKey()));
});

it('offers "Pay now" on a pending order that nobody has tried to pay yet', function (): void {
    $this->withBrand();
    $me = User::factory()->create();

    $order = Order::factory()->forPayer($me->getKey())->create();
    OrderLine::factory()->forOrder($order)->create(['label' => 'Weekend ticket']);

    $this->actingAs($me)
        ->get('/my/orders/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.settlesType', 'registration')
            ->where('order.paymentStatus', null)
            ->where('order.repayDueOn', null)
            ->where('order.payUrl', '/pay/registration/'.$order->settles_id));
});

/**
 * Task 17b of the same round: a product line links to its shop page — but
 * only to one that answers. `Cart\ProductPageUrls` resolves the variant's
 * product slug when the product is still published and the brand has the
 * shop on; an unpublished product, or a brand with the shop off, leaves the
 * line as plain text rather than a link to a 404.
 */
it('links a product line to its shop page only while that page answers', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $me = User::factory()->create();
    $published = ProductVariant::factory()
        ->for(Product::factory()->published()->create(['slug' => 'event-shirt']), 'product')
        ->create();
    $draft = ProductVariant::factory()
        ->for(Product::factory()->create(['slug' => 'not-yet']), 'product')
        ->create();

    $order = Order::factory()->forPayer($me->getKey())->create(['settles_type' => 'cart', 'settles_id' => (string) Str::ulid()]);
    OrderLine::factory()->forOrder($order)->create(['settles_kind' => 'product', 'settles_ref' => $published->getKey()]);
    OrderLine::factory()->forOrder($order)->create(['position' => 2, 'settles_kind' => 'product', 'settles_ref' => $draft->getKey()]);

    $this->actingAs($me)
        ->get('/my/orders/'.$order->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.lines.0.productUrl', '/shop/event-shirt')
            ->where('order.lines.0.registrationUrl', null)
            ->where('order.lines.1.productUrl', null));

    Feature::for($brand)->deactivate('commerce:shop');

    $this->actingAs($me)
        ->get('/my/orders/'.$order->getKey())
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.lines.0.productUrl', null));
});
