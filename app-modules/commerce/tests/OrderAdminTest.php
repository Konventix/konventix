<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0029: Commerce's operator-console surface — the orders index and
 * first-class manual payment booking (SPEC.md "Owns").
 */
function commerceOperator(array $permissions = ['platform.access-admin', 'commerce.view-orders', 'commerce.book-manual-payment']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.commerce.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

it('404s the orders index on a brand host', function (): void {
    commerceOperator();

    $this->get('http://localhost/admin/orders')->assertNotFound();
});

it('403s an admin without the view-orders capability', function (): void {
    [, $admin] = commerceOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.commerce.test/admin/orders')
        ->assertForbidden();
});

it('lists orders with their invoice across brands', function (): void {
    [, $admin] = commerceOperator();

    $this->withBrand();
    $order = Order::factory()->create();
    $invoice = Invoice::factory()->create(['order_id' => $order->id]);

    $this->actingAs($admin)
        ->get('http://admin.commerce.test/admin/orders')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/orders/Index')
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $order->id)
            ->where('orders.data.0.invoice.number', $invoice->invoice_number));
});

it('shows the settlement provider on the orders list, from the latest succeeded payment', function (): void {
    [, $admin] = commerceOperator();

    $this->withBrand();
    $order = Order::factory()->create();
    Payment::factory()->failed()->create(['order_id' => $order->id, 'provider' => 'mollie']);
    Payment::factory()->succeeded()->create(['order_id' => $order->id, 'provider' => 'stripe']);
    $unpaidOrder = Order::factory()->create();

    $response = $this->actingAs($admin)
        ->get('http://admin.commerce.test/admin/orders')
        ->assertOk();

    $rows = collect($response->viewData('page')['props']['orders']['data']);

    expect($rows->firstWhere('id', $order->id)['provider'])->toBe('stripe')
        ->and($rows->firstWhere('id', $unpaidOrder->id)['provider'])->toBeNull();
});

it('books a manual payment, emitting OrderPaid', function (): void {
    [, $admin] = commerceOperator();

    $this->withBrand();
    $order = Order::factory()->create(['status' => OrderStatus::Pending, 'total_gross' => '297.50']);

    Event::fake([OrderPaid::class]);

    $this->actingAs($admin)
        ->post("http://admin.commerce.test/admin/orders/{$order->id}/payment")
        ->assertRedirect();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull();

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $event): bool => $event->orderId === $order->id
        && $event->amountPaid === '297.50'
        && $event->settlementMethod === 'manual');
});

it('403s payment booking without the book-manual-payment capability', function (): void {
    [, $admin] = commerceOperator(['platform.access-admin', 'commerce.view-orders']);

    $this->withBrand();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    $this->actingAs($admin)
        ->post("http://admin.commerce.test/admin/orders/{$order->id}/payment")
        ->assertForbidden();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('shows one order with lines, invoice, payments and credit notes', function (): void {
    [, $admin] = commerceOperator();

    $this->withBrand();
    $order = Order::factory()->create(['currency' => 'EUR', 'total_gross' => '119.00']);
    $invoice = Invoice::factory()->create(['order_id' => $order->id]);
    $line = OrderLine::factory()->create([
        'order_id' => $order->id,
        'label' => 'Exhibitor package',
    ]);
    $payment = Payment::factory()->succeeded()->create(['order_id' => $order->id, 'provider' => 'stripe']);
    $note = CreditNote::factory()->create([
        'order_id' => $order->id,
        'invoice_id' => $invoice->id,
        'billing_party' => $invoice->billing_party,
        'seller' => $invoice->seller,
        // Sealed already (Task 3, ADR-0048 point 2) — the console's own
        // Gutschrift PDF link only renders once documentReady is true.
        'document_path' => 'GS-TEST/2026/GS-TEST-2026-00001.pdf',
        'document_disk' => 'invoices',
    ]);

    $this->actingAs($admin)
        ->get("http://admin.commerce.test/admin/orders/{$order->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/orders/Show')
            ->where('order.id', $order->id)
            ->where('order.provider', 'stripe')
            ->where('lines.0.label', 'Exhibitor package')
            ->where('lines.0.id', $line->id)
            ->where('invoice.number', $invoice->invoice_number)
            ->where('payments.0.id', $payment->id)
            ->where('creditNotes.0.number', $note->credit_note_number)
            ->where('creditNotes.0.documentReady', true));
});

it('403s the order detail without the view-orders capability', function (): void {
    [, $admin] = commerceOperator(['platform.access-admin']);

    $this->withBrand();
    $order = Order::factory()->create();

    $this->actingAs($admin)
        ->get("http://admin.commerce.test/admin/orders/{$order->id}")
        ->assertForbidden();
});

it('defaults the brand filter to the console selection and lets the list clear it', function (): void {
    [, $admin] = commerceOperator();

    $sxlan = $this->withBrand(Brand::factory()->create(['name' => 'SXLan']));
    $sxlanOrder = Order::factory()->create();

    $this->withBrand(Brand::factory()->create(['name' => 'Xpertise']));
    Order::factory()->create();

    $this->actingAs($admin)
        ->put('http://admin.commerce.test/admin/selection', ['brandId' => $sxlan->getKey(), 'eventId' => null])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get('http://admin.commerce.test/admin/orders')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', $sxlan->getKey())
            ->count('orders.data', 1)
            ->where('orders.data.0.id', $sxlanOrder->id));

    // A default, not a scope: "all brands" is a legitimate console view.
    $this->actingAs($admin)
        ->get('http://admin.commerce.test/admin/orders?brand=all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', null)
            ->count('orders.data', 2));
});
