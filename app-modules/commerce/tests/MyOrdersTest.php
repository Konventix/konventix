<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;

/**
 * "Show me everything I owe" — the list ADR-0044 makes buildable. Brand-scoped
 * by the ordinary global scope: an order placed with another brand is
 * invisible here even to the account that placed it (ADR-0016 from outside).
 */
it('lists the signed-in payer\'s orders on this brand and nobody else\'s', function (): void {
    $brand = $this->withBrand();
    $me = User::factory()->create();

    $mine = Order::factory()->forPayer($me->getKey())->create(['total_gross' => '35.70']);
    Order::factory()->forPayer(User::factory()->create()->getKey())->create();
    Order::factory()->create(); // pre-ADR order with no payer

    $this->withBrand(Brand::factory()->create());
    Order::factory()->forPayer($me->getKey())->create();
    $this->withBrand($brand);

    $this->actingAs($me)
        ->get('/my/orders')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('commerce/my/Index')
            ->has('orders', 1)
            ->where('orders.0.id', $mine->getKey())
            ->where('orders.0.totalGross', '35.70')
            ->where('orders.0.payUrl', '/pay/registration/'.$mine->settles_id)
            ->where('orders.0.invoiceNumber', null));
});

it('offers the invoice through a signed link once one is archived', function (): void {
    $this->withBrand();
    Storage::fake('invoices');
    Storage::disk('invoices')->put('archive/inv.pdf', '%PDF-1.4 sealed');
    $me = User::factory()->create();

    $order = Order::factory()->forPayer($me->getKey())->paid()->create();
    $invoice = Invoice::factory()->create([
        'order_id' => $order->getKey(),
        'invoice_number' => 'KVX-2026-00042',
        'document_path' => 'archive/inv.pdf',
        'document_disk' => 'invoices',
    ]);

    $this->actingAs($me)
        ->get('/my/orders')
        ->assertInertia(function (AssertableInertia $page) use ($invoice): void {
            $row = $page->toArray()['props']['orders'][0];

            expect($row['invoiceNumber'])->toBe('KVX-2026-00042')
                ->and($row['payUrl'])->toBeNull()
                ->and($row['documentUrl'])->toContain($invoice->getKey())
                ->and($row['documentUrl'])->toContain('signature=');
        });
});

it('renders a cart order\'s lines, linking its registration line to the account page', function (): void {
    // ADR-0047: a cart order settles a mix of products and registrations —
    // the order-level summary line does not say which is which, so "my
    // orders" carries the per-line breakdown too.
    $brand = $this->withBrand();
    $me = User::factory()->create();
    $registrationId = (string) Str::ulid();

    $order = Order::factory()->forPayer($me->getKey())->create([
        'settles_type' => 'cart',
        'settles_id' => (string) Str::ulid(),
        'total_gross' => '83.30',
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

    $this->actingAs($me)
        ->get('/my/orders')
        ->assertInertia(function (AssertableInertia $page) use ($registrationId): void {
            $lines = collect($page->toArray()['props']['orders'][0]['lines'])->keyBy('kind');

            expect($lines['product']['registrationUrl'])->toBeNull()
                ->and($lines['registration']['label'])->toBe('Weekend Ticket')
                ->and($lines['registration']['registrationUrl'])->toBe('/my/registrations/'.$registrationId);
        });
});

it('sends a guest to the login page', function (): void {
    $this->withBrand();

    $this->get('/my/orders')->assertRedirect('/login');
});

it('appears in the account menu for a signed-in member', function (): void {
    $this->withBrand();

    $this->actingAs(User::factory()->create())
        ->get('/my/orders')
        ->assertInertia(fn (AssertableInertia $page) => expect(collect($page->toArray()['props']['accountNav'])->pluck('label')->all())->toContain('My orders'));
});

it('keeps the pay link on an order whose payment was reversed', function (): void {
    // ADR-0057 point 3: the sale stands and the amount is owed again, so
    // "my orders" must still offer the way to pay it (C1).
    $this->withBrand();
    $me = User::factory()->create();

    $order = Order::factory()->forPayer($me->getKey())->paid()->create();
    $order->forceFill(['status' => OrderStatus::PaymentReversed])->save();

    $this->actingAs($me)
        ->get('/my/orders')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('orders.0.payUrl', '/pay/registration/'.$order->settles_id));
});
