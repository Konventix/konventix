<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Identity\Models\User;
use Konventix\Registration\Models\Registration;

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
