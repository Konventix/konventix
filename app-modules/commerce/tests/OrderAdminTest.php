<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Actions\CancelOrderForUnpaidReversal;
use Konventix\Commerce\Actions\IssueCreditNote;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Events\OrderLineCancelled;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Exceptions\ProviderRefusedRefundException;
use Konventix\Commerce\Models\CheckedInAdmission;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\SettlementAdapter;
use Konventix\Commerce\Settlement\SettlementAdapterRegistry;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCheckoutSession;
use Konventix\Compliance\Models\AuditLogEntry;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Stripe\Exception\InvalidRequestException;

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

/**
 * ADR-0057 point 4: the operator's manual "cancel now" for a reversed order
 * whose payment never came back — the deadline sweep does the same thing
 * automatically, this lets the operator do it earlier. Same capability as
 * booking a payment: both are treasury actions on a reversed order
 * (`OrderAdminController::cancelReversed`).
 */
it('cancels a payment_reversed order by hand, emitting OrderCancelled', function (): void {
    [, $admin] = commerceOperator();

    $this->withBrand();
    $order = Order::factory()->create(['status' => OrderStatus::PaymentReversed]);

    Event::fake([OrderCancelled::class]);

    $this->actingAs($admin)
        ->post("http://admin.commerce.test/admin/orders/{$order->id}/cancel-reversed")
        ->assertRedirect();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);

    Event::assertDispatched(OrderCancelled::class, fn (OrderCancelled $event): bool => $event->orderId === $order->id
        && $event->reasonCode === CancelOrderForUnpaidReversal::REASON_REVERSAL_UNPAID
        && $event->cancelledByUserId === $admin->id);
});

it('422s cancelling an order that is not payment_reversed, leaving it unchanged', function (): void {
    [, $admin] = commerceOperator();

    $this->withBrand();
    $order = Order::factory()->create(['status' => OrderStatus::Paid, 'paid_at' => now()]);

    $this->actingAs($admin)
        ->postJson("http://admin.commerce.test/admin/orders/{$order->id}/cancel-reversed")
        ->assertStatus(422);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

/**
 * `CancelOrderForUnpaidReversal` deliberately does not re-guard the
 * checked-in admission the sweep skips for (its own docblock: "An operator
 * who cancels by hand has already made that judgement") — the mirror of
 * `RefundBlockedByCheckInException`'s "cancellation is deliberately not
 * blocked, only the money flow" for the refund path. Only the sweep defers
 * to dunning instead of cancelling; the manual button does not.
 */
it('still cancels by hand when the admission is checked in, unlike the automatic sweep', function (): void {
    [, $admin] = commerceOperator();

    $this->withBrand();
    $registrationId = (string) Str::ulid();
    $order = Order::factory()->create([
        'status' => OrderStatus::PaymentReversed,
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
    ]);
    CheckedInAdmission::query()->create([
        'admission_id' => (string) Str::ulid(),
        'registration_id' => $registrationId,
        'first_checked_in_at' => now()->subDay(),
    ]);

    Event::fake([OrderCancelled::class]);

    $this->actingAs($admin)
        ->post("http://admin.commerce.test/admin/orders/{$order->id}/cancel-reversed")
        ->assertRedirect();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('403s cancelling a reversed order without the book-manual-payment capability', function (): void {
    [, $admin] = commerceOperator(['platform.access-admin', 'commerce.view-orders']);

    $this->withBrand();
    $order = Order::factory()->create(['status' => OrderStatus::PaymentReversed]);

    $this->actingAs($admin)
        ->post("http://admin.commerce.test/admin/orders/{$order->id}/cancel-reversed")
        ->assertForbidden();

    expect($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);
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

/**
 * ADR-0057 point 8: the orders console gains the reversal list. The view
 * gathers two different kinds of trouble — money taken back (an open or a
 * final reversal) and money that arrived for an order that cannot take it
 * (ADR-0057 point 9, #6) — because both are the same operator task.
 */
it('lists orders with an open or final reversal and orders with unapplied money under the reversals view', function (): void {
    [, $admin] = commerceOperator();

    $this->withBrand();

    $untouched = Order::factory()->create(['status' => OrderStatus::Paid, 'paid_at' => now()]);

    $reversedOrder = Order::factory()->create([
        'status' => OrderStatus::PaymentReversed,
        'currency' => 'EUR',
        'paid_at' => now()->subDay(),
    ]);
    $reversedPayment = Payment::factory()->create([
        'order_id' => $reversedOrder->id,
        'provider' => 'stripe',
        'status' => PaymentStatus::Reversed,
        'amount' => '119.00',
    ]);
    PaymentReversal::factory()->create([
        'order_id' => $reversedOrder->id,
        'payment_id' => $reversedPayment->id,
        'kind' => ReversalKind::Return,
        'status' => ReversalStatus::Final,
        'amount' => '119.00',
        'provider_reference' => 'dp_return_1',
        'reason_code' => 'insufficient_funds',
        'repay_due_on' => '2026-09-14',
    ]);

    $disputedOrder = Order::factory()->create(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    PaymentReversal::factory()->open()->create([
        'order_id' => $disputedOrder->id,
        'provider_reference' => 'dp_open_1',
    ]);

    // A dispute the organizer won moved no money and is not a task.
    $wonOrder = Order::factory()->create(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    PaymentReversal::factory()->create([
        'order_id' => $wonOrder->id,
        'status' => ReversalStatus::Withdrawn,
        'provider_reference' => 'dp_won_1',
    ]);

    $cancelledOrder = Order::factory()->create(['status' => OrderStatus::Cancelled, 'currency' => 'EUR']);
    Payment::factory()->succeeded()->create([
        'order_id' => $cancelledOrder->id,
        'provider' => 'mollie',
        'amount' => '59.00',
    ]);

    $response = $this->actingAs($admin)
        ->get('http://admin.commerce.test/admin/orders?view=reversals')
        ->assertOk();

    $rows = collect($response->viewData('page')['props']['orders']['data']);

    expect($rows->pluck('id')->sort()->values()->all())->toBe(
        collect([$reversedOrder->id, $disputedOrder->id, $cancelledOrder->id])->sort()->values()->all()
    );

    $reversedRow = $rows->firstWhere('id', $reversedOrder->id);

    // ADR-0009: `OrderReversalData` is the single source of this shape, and
    // the generated TypeScript the pages read is built from the same class —
    // so the key set is worth pinning, not just the values.
    expect(array_keys($reversedRow['reversals'][0]))->toBe([
        'id',
        'provider',
        'kind',
        'status',
        'amount',
        'currency',
        'providerReference',
        'reasonCode',
        'feeAmount',
        'occurredAt',
        'finalizedAt',
        'repayDueOn',
    ]);

    expect($reversedRow['reversals'])->toHaveCount(1)
        ->and($reversedRow['reversals'][0]['kind'])->toBe('return')
        ->and($reversedRow['reversals'][0]['status'])->toBe('final')
        ->and($reversedRow['reversals'][0]['amount'])->toBe('119.00')
        ->and($reversedRow['reversals'][0]['providerReference'])->toBe('dp_return_1')
        ->and($reversedRow['reversals'][0]['reasonCode'])->toBe('insufficient_funds')
        ->and($reversedRow['reversals'][0]['repayDueOn'])->toBe('2026-09-14')
        ->and($reversedRow['unappliedPayment'])->toBeNull();

    $disputedRow = $rows->firstWhere('id', $disputedOrder->id);

    expect($disputedRow['reversals'][0]['status'])->toBe('open')
        ->and($disputedRow['reversals'][0]['kind'])->toBe('dispute')
        ->and($disputedRow['reversals'][0]['repayDueOn'])->toBeNull();

    $unappliedRow = $rows->firstWhere('id', $cancelledOrder->id);

    expect(array_keys($unappliedRow['unappliedPayment']))->toBe([
        'id',
        'provider',
        'amount',
        'currency',
        'externalId',
        'receivedAt',
    ]);

    expect($unappliedRow['reversals'])->toBe([])
        ->and($unappliedRow['unappliedPayment']['provider'])->toBe('mollie')
        ->and($unappliedRow['unappliedPayment']['amount'])->toBe('59.00')
        ->and($unappliedRow['unappliedPayment']['receivedAt'])->not->toBeNull();

    expect($rows->firstWhere('id', $untouched->id))->toBeNull()
        ->and($rows->firstWhere('id', $wonOrder->id))->toBeNull();
});

/**
 * #238: the orders list printed "Placed" as the string the server had
 * formatted, which is UTC — a reader in Berlin saw two hours too little, and
 * #208 introduced `formatDateTime()` in `resources/js/lib/datetime.ts` for
 * exactly that. A system timestamp reaches the browser as an ISO-8601
 * instant and is rendered in the reader's own zone; only a calendar date
 * (`dueOn`, `issuedOn`, `repayDueOn`) stays a date, because it is one.
 */
it('sends every order timestamp as an ISO instant, not a preformatted string (#238)', function (): void {
    [, $admin] = commerceOperator();

    $brand = $this->withBrand();

    $order = Order::factory()->paid()->create(['paid_at' => now()->subHour()]);

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'mollie',
        'received_at' => now()->subMinutes(30),
    ]);

    PaymentReversal::factory()->create([
        'order_id' => $order->id,
        'payment_id' => $payment->id,
        'brand_id' => $brand->id,
        'kind' => ReversalKind::Return,
        'status' => ReversalStatus::Final,
        'occurred_at' => now()->subMinutes(20),
        'finalized_at' => now()->subMinutes(10),
    ]);

    $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

    $response = $this->actingAs($admin)
        ->get('http://admin.commerce.test/admin/orders')
        ->assertOk();

    $row = collect($response->viewData('page')['props']['orders']['data'])
        ->firstWhere('id', $order->id);

    expect($row['createdAt'])->toMatch($iso)
        ->and($row['paidAt'])->toMatch($iso)
        ->and($row['reversals'][0]['occurredAt'])->toMatch($iso)
        ->and($row['reversals'][0]['finalizedAt'])->toMatch($iso);

    $show = $this->actingAs($admin)
        ->get("http://admin.commerce.test/admin/orders/{$order->id}")
        ->assertOk();

    $props = $show->viewData('page')['props'];

    expect($props['order']['createdAt'])->toMatch($iso)
        ->and($props['order']['paidAt'])->toMatch($iso)
        ->and($props['payments'][0]['receivedAt'])->toMatch($iso)
        ->and($props['reversals'][0]['occurredAt'])->toMatch($iso)
        // A due date is a calendar date, not an instant — it must not move
        // with the reader (the other half of the same distinction).
        ->and($props['invoice']['dueOn'] ?? '2026-09-14')->not->toMatch($iso);
});

it('echoes the reversals view back and drops a view it does not offer', function (): void {
    [, $admin] = commerceOperator();

    $this->withBrand();
    Order::factory()->create();

    $this->actingAs($admin)
        ->get('http://admin.commerce.test/admin/orders?view=reversals')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.view', 'reversals'));

    $this->actingAs($admin)
        ->get('http://admin.commerce.test/admin/orders?view=nonsense')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.view', null)
            ->count('orders.data', 1));
});

it('keeps the reversals view inside the brand filter', function (): void {
    [, $admin] = commerceOperator();

    $sxlan = $this->withBrand(Brand::factory()->create(['name' => 'SXLan']));
    $sxlanOrder = Order::factory()->create(['status' => OrderStatus::PaymentReversed]);
    PaymentReversal::factory()->create([
        'order_id' => $sxlanOrder->id,
        'provider_reference' => 'dp_sxlan',
    ]);
    // Same brand, nothing wrong with it: the count below only holds if the
    // view filter and the brand filter both apply.
    Order::factory()->create(['status' => OrderStatus::Paid, 'paid_at' => now()]);

    $this->withBrand(Brand::factory()->create(['name' => 'Xpertise']));
    $otherOrder = Order::factory()->create(['status' => OrderStatus::PaymentReversed]);
    PaymentReversal::factory()->create([
        'order_id' => $otherOrder->id,
        'provider_reference' => 'dp_xpertise',
    ]);

    $this->actingAs($admin)
        ->get("http://admin.commerce.test/admin/orders?view=reversals&brand={$sxlan->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->count('orders.data', 1)
            ->where('orders.data.0.id', $sxlanOrder->id));

    $this->actingAs($admin)
        ->get('http://admin.commerce.test/admin/orders?view=reversals&brand=all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->count('orders.data', 2));
});

/**
 * ADR-0057 point 8 on the detail page: every reversal row the order has,
 * withdrawn ones included — the console is where an operator reconstructs
 * what the provider did, and a won dispute is part of that story.
 */
it('shows the order reversals, withdrawn ones included, with their deadline', function (): void {
    [, $admin] = commerceOperator();

    $this->withBrand();
    $order = Order::factory()->create([
        'status' => OrderStatus::PaymentReversed,
        'currency' => 'EUR',
        'paid_at' => now()->subDay(),
    ]);
    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'status' => PaymentStatus::Reversed,
    ]);
    PaymentReversal::factory()->create([
        'order_id' => $order->id,
        'payment_id' => $payment->id,
        'kind' => ReversalKind::Return,
        'status' => ReversalStatus::Final,
        'amount' => '119.00',
        'provider_reference' => 'dp_return_show',
        'reason_code' => 'insufficient_funds',
        'fee_amount' => '15.00',
        'repay_due_on' => '2026-09-14',
        'occurred_at' => now()->subHours(2),
    ]);
    PaymentReversal::factory()->create([
        'order_id' => $order->id,
        'payment_id' => $payment->id,
        'kind' => ReversalKind::Dispute,
        'status' => ReversalStatus::Withdrawn,
        'provider_reference' => 'dp_won_show',
        'repay_due_on' => null,
        'occurred_at' => now()->subDays(3),
    ]);

    $this->actingAs($admin)
        ->get("http://admin.commerce.test/admin/orders/{$order->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/orders/Show')
            ->has('reversals', 2)
            ->where('reversals.0.providerReference', 'dp_return_show')
            ->where('reversals.0.kind', 'return')
            ->where('reversals.0.status', 'final')
            ->where('reversals.0.amount', '119.00')
            ->where('reversals.0.currency', 'EUR')
            ->where('reversals.0.provider', 'stripe')
            ->where('reversals.0.reasonCode', 'insufficient_funds')
            ->where('reversals.0.feeAmount', '15.00')
            ->where('reversals.0.repayDueOn', '2026-09-14')
            ->where('reversals.1.providerReference', 'dp_won_show')
            ->where('reversals.1.status', 'withdrawn')
            ->where('reversals.1.repayDueOn', null)
            ->where('unappliedPayment', null));
});

/**
 * ADR-0057 point 9 / #6: a succeeded payment on an order that is not paid
 * is money nobody applied. The callout names the provider, the amount and
 * when it arrived; what to do about it stays the operator's decision.
 */
it('shows the unapplied-payment callout for money that arrived after cancellation', function (): void {
    [, $admin] = commerceOperator();

    $this->withBrand();
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled, 'currency' => 'EUR']);
    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'stripe',
        'amount' => '59.00',
    ]);

    $this->actingAs($admin)
        ->get("http://admin.commerce.test/admin/orders/{$order->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('unappliedPayment.id', $payment->id)
            ->where('unappliedPayment.provider', 'stripe')
            ->where('unappliedPayment.amount', '59.00')
            ->where('unappliedPayment.currency', 'EUR')
            ->whereNot('unappliedPayment.receivedAt', null)
            ->has('reversals', 0));
});

/**
 * The contract the console's "Book bank transfer" button depends on
 * (I2): `RecordPaymentReversal` keeps `paid_at` on purpose, so the button's
 * old `!paidAt` test hid it for every reversed order — the manual
 * repayment path ADR-0057 point 3 names ("the door POS or a bank transfer")
 * was unreachable in the UI while the route behind it worked all along.
 * `resources/js/pages/admin/orders/reversals.test.ts` pins the condition
 * itself.
 */
it('books a manual payment on a payment_reversed order, the way the console now offers', function (): void {
    [, $admin] = commerceOperator();

    $this->withBrand();
    $order = Order::factory()->paid()->create(['total_gross' => '119.00']);
    $order->forceFill(['status' => OrderStatus::PaymentReversed])->save();

    Event::fake([OrderPaid::class]);

    $this->actingAs($admin)
        ->post("http://admin.commerce.test/admin/orders/{$order->id}/payment")
        ->assertRedirect();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $event): bool => $event->orderId === $order->id
        && $event->settlementMethod === 'manual');
});

/**
 * #73's first open item: the whole-order refund an operator can reach from
 * the console at all. `RefundOrder` has done the work since ADR-0037 and
 * was reachable only from tests and tinker; these cases pin the console
 * route onto it — the reason travels with the money (ADR-0031's credit
 * note carries it), and every refusal the Action makes comes back as a
 * form error rather than a 500.
 *
 * Partial refunds, ticket transfers and seat release are the issue's other
 * open items and deliberately not here: this route refunds the order whole.
 */
function orderAdminRefundPermissions(): array
{
    return ['platform.access-admin', 'commerce.view-orders', 'commerce.issue-refunds'];
}

it('refunds a whole paid order from the console, crediting its invoice', function (): void {
    [, $admin] = commerceOperator(orderAdminRefundPermissions());

    $this->withBrand();
    // No payment row: `RefundOrder` then resolves the manual adapter, whose
    // refund is a no-op — the money leg of a bank transfer is the bank's.
    $order = Order::factory()->paid()->create(['total_gross' => '119.00']);
    Invoice::factory()->create(['order_id' => $order->id]);

    Event::fake([OrderRefunded::class]);

    $this->actingAs($admin)
        ->post("http://admin.commerce.test/admin/orders/{$order->id}/refund", [
            'reasonCode' => 'customer-request',
            'reasonNote' => 'Cancelled by phone.',
        ])
        ->assertRedirect();

    // `withoutBrandScope()`: the request ran on the admin host, which
    // leaves no resolved brand and hardens BrandScope to zero rows
    // (ADR-0029, `.ai/rules/tests.md`).
    /** @var CreditNote $creditNote */
    $creditNote = CreditNote::withoutBrandScope()->where('order_id', $order->id)->sole();

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        // The operator's own words survive the round trip — the only thing
        // this dialog durably persists. `reason_code` is the document's
        // vocabulary, not the console's: `RecordRefund` always writes
        // `refund`, which is why the dialog does not promise otherwise.
        ->and($creditNote->reason_note)->toBe('Cancelled by phone.')
        ->and($creditNote->reason_code)->toBe(IssueCreditNote::REASON_REFUND);

    Event::assertDispatched(OrderRefunded::class, fn (OrderRefunded $event): bool => $event->orderId === $order->id
        && $event->reasonCode === 'customer-request'
        && $event->creditNoteId !== null
        && $event->whole === true);
    // Exactly once: a second dispatch would have Registration cancel twice
    // (ADR-0057 point 5) and any refund counter double-count.
    Event::assertDispatchedTimes(OrderRefunded::class, 1);

    // Who sent the money back, and why — the console's own audit entry, the
    // shape `SettlementAccountAdminController` writes for a saved Stripe key.
    $audit = AuditLogEntry::query()->where('action', 'commerce.order.refunded')->sole();

    expect($audit->actor_user_id)->toBe((string) $admin->id)
        ->and($audit->subject_id)->toBe($order->id)
        ->and($audit->brand_id)->toBe($order->brand_id)
        ->and($audit->context)->toBe([
            'reason_code' => 'customer-request',
            'reason_note' => 'Cancelled by phone.',
            'amount' => '119.00',
            'currency' => $order->currency,
            // The clean path (fix round 1, NEW-1): the seat went with the
            // money. Its counterpart is asserted true below.
            'announcement_failed' => false,
        ]);
});

it('422s a refund the check-in guard blocks, naming the reason and leaving the order paid', function (): void {
    // ADR-0037 / D-COM-15: someone attended, so returning the money is a
    // decision a human makes deliberately — an explicit refusal with a
    // reason, never a silent no-op and never a 500.
    [, $admin] = commerceOperator(orderAdminRefundPermissions());

    $this->withBrand();
    $registrationId = (string) Str::ulid();
    $order = Order::factory()->paid()->create([
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
    ]);
    CheckedInAdmission::query()->create([
        'admission_id' => (string) Str::ulid(),
        'registration_id' => $registrationId,
        'first_checked_in_at' => now()->subHour(),
    ]);

    $this->actingAs($admin)
        ->postJson("http://admin.commerce.test/admin/orders/{$order->id}/refund", [
            'reasonCode' => 'customer-request',
        ])
        ->assertStatus(422)
        ->assertInvalid(['order' => 'ADR-0037']);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0);
});

it('422s a refund of an order that was never paid', function (): void {
    [, $admin] = commerceOperator(orderAdminRefundPermissions());

    $this->withBrand();
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    $this->actingAs($admin)
        ->postJson("http://admin.commerce.test/admin/orders/{$order->id}/refund", [
            'reasonCode' => 'customer-request',
        ])
        ->assertStatus(422)
        ->assertInvalid(['order' => 'only a paid order']);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('422s a refund with no reason, or one the console does not offer', function (): void {
    // The dialog pre-picks nothing, so "no reason" is a submission the
    // server actually sees: an irreversible money movement is never
    // justified by a default nobody chose.
    [, $admin] = commerceOperator(orderAdminRefundPermissions());

    $this->withBrand();
    $order = Order::factory()->paid()->create();

    $this->actingAs($admin)
        ->postJson("http://admin.commerce.test/admin/orders/{$order->id}/refund", [])
        ->assertStatus(422)
        ->assertInvalid(['reasonCode']);

    $this->actingAs($admin)
        ->postJson("http://admin.commerce.test/admin/orders/{$order->id}/refund", [
            'reasonCode' => 'because-i-said-so',
        ])
        ->assertStatus(422)
        ->assertInvalid(['reasonCode']);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(AuditLogEntry::query()->where('action', 'commerce.order.refunded')->count())->toBe(0);
});

/**
 * #236: `SdkStripeApi` lets the SDK's own exception out, and
 * `Stripe\Exception\ApiErrorException` is not a `RuntimeException` — the
 * console answered 500 and told the operator nothing. The second attempt at
 * a refund Stripe had already made is exactly that case, and it is the one
 * an operator hits after the first attempt half-failed.
 */
final class OrderAdminTestRefusingStripeApi implements StripeApi
{
    public function createCheckoutSession(array $params): StripeCheckoutSession
    {
        return new StripeCheckoutSession(id: 'cs_test_fake', url: 'https://checkout.stripe.test/pay/cs_test_fake');
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

    public function createRefund(array $params): void
    {
        throw InvalidRequestException::factory(
            message: 'Charge ch_3UDIXCDvM5KSDNOI2 has already been refunded.',
            httpStatus: 400,
            stripeParam: 'charge',
        );
    }

    /**
     * @return array{status: string, payment_status: string, payment_intent: ?string, payment_intent_status: ?string}
     */
    public function fetchCheckoutSession(string $sessionId): array
    {
        return [
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => null,
            'payment_intent_status' => null,
        ];
    }
}

function orderAdminStripePaidOrder(): Order
{
    $account = SettlementAccount::factory()->create([
        'provider' => SettlementAccount::PROVIDER_STRIPE,
    ]);

    $order = Order::factory()->paid()->create(['total_gross' => '11.90']);

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'settlement_account_id' => $account->id,
        'provider_transaction_id' => 'pi_3UDIXCDvM5KSDNOI2yhJYJ26',
        'amount' => '11.90',
        'currency' => $order->currency,
    ]);

    app()->instance(StripeApi::class, new OrderAdminTestRefusingStripeApi);

    return $order;
}

it('422s a refund Stripe refuses, naming Stripe\'s own message instead of answering 500 (#236)', function (): void {
    [, $admin] = commerceOperator(orderAdminRefundPermissions());

    $this->withBrand();
    $order = orderAdminStripePaidOrder();

    $this->actingAs($admin)
        ->postJson("http://admin.commerce.test/admin/orders/{$order->id}/refund", [
            'reasonCode' => 'customer-request',
        ])
        ->assertStatus(422)
        ->assertInvalid(['order' => 'has already been refunded']);

    // And the operator is told what to do about money the provider says is
    // already back: reconcile it, rather than press the button again.
    $this->actingAs($admin)
        ->postJson("http://admin.commerce.test/admin/orders/{$order->id}/refund", [
            'reasonCode' => 'customer-request',
        ])
        ->assertInvalid(['order' => 'reconcile']);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0);
});

/**
 * #236 fix round 1, review finding 5: the console must read the same for
 * every provider. The refusal arrives as one Commerce exception whichever
 * SDK raised it, so this exercises the path with an adapter that owes
 * nothing to any SDK — and pins the two sentences apart: a refusal asks for
 * a reconciliation, a rejected key asks for the credentials (review nit 7).
 */
final class OrderAdminTestRefusingAdapter implements SettlementAdapter
{
    public bool $misconfigured = false;

    public function markOrderPaid(
        string $accountId,
        Order $order,
        string $amountPaid,
        string $currency,
        string $settlementMethod,
    ): Order {
        return $order;
    }

    public function refund(string $accountId, Order $order, string $amount, string $currency): void
    {
        throw $this->misconfigured
            ? ProviderRefusedRefundException::misconfigured('spy', 'Invalid API Key provided.')
            : ProviderRefusedRefundException::refused('spy', 'The charge has already been refunded.');
    }
}

function orderAdminSpyRefundOrder(bool $misconfigured = false): Order
{
    $order = Order::factory()->paid()->create(['total_gross' => '11.90']);

    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => 'spy',
        'settlement_account_id' => (string) Str::ulid(),
        'amount' => '11.90',
        'currency' => $order->currency,
    ]);

    app(SettlementAdapterRegistry::class)->register('spy', OrderAdminTestRefusingAdapter::class);
    $spy = app(OrderAdminTestRefusingAdapter::class);
    $spy->misconfigured = $misconfigured;
    app()->instance(OrderAdminTestRefusingAdapter::class, $spy);

    return $order;
}

it('422s a provider refusal from any adapter, not only Stripe (#236)', function (): void {
    [, $admin] = commerceOperator(orderAdminRefundPermissions());

    $this->withBrand();
    $order = orderAdminSpyRefundOrder();

    $this->actingAs($admin)
        ->postJson("http://admin.commerce.test/admin/orders/{$order->id}/refund", [
            'reasonCode' => 'customer-request',
        ])
        ->assertStatus(422)
        ->assertInvalid(['order' => 'has already been refunded'])
        ->assertInvalid(['order' => 'reconcile']);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0);
});

it('reads a rejected provider key as a settings problem, not as a refusal (#236)', function (): void {
    [, $admin] = commerceOperator(orderAdminRefundPermissions());

    $this->withBrand();
    $order = orderAdminSpyRefundOrder(misconfigured: true);

    $this->actingAs($admin)
        ->postJson("http://admin.commerce.test/admin/orders/{$order->id}/refund", [
            'reasonCode' => 'customer-request',
        ])
        ->assertStatus(422)
        ->assertInvalid(['order' => 'credentials']);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

/**
 * #236 fix round 1, review NEW-1: `RefundEventRelayFailedException` is a
 * `RuntimeException`, and under a `sync` queue the relay runs inside the
 * after-commit callback — so it landed in the arm meant for "nothing moved"
 * and the operator was told 422 about a refund that had gone through, with
 * no audit row and no toast. The money is back with the payer at that point;
 * the only honest answer is that the refund stands and the follow-up needs
 * looking at.
 */
it('reports a refund whose announcement failed as recorded, not as refused (#236)', function (): void {
    [, $admin] = commerceOperator(orderAdminRefundPermissions());

    $this->withBrand();

    // A cart order, so the refund emits `OrderLineCancelled` per line — the
    // event whose consumer actually blew up on dev.
    $order = Order::factory()->paid()->create([
        'settles_type' => 'cart',
        'settles_id' => (string) Str::ulid(),
        'total_gross' => '11.90',
    ]);
    Invoice::factory()->create(['order_id' => $order->id]);
    OrderLine::factory()->create([
        'order_id' => $order->id,
        'position' => 1,
        'settles_kind' => 'registration',
        'settles_ref' => (string) Str::ulid(),
    ]);

    Event::listen(OrderLineCancelled::class, function (): void {
        throw new RuntimeException('the consumer blew up');
    });

    $this->actingAs($admin)
        ->post("http://admin.commerce.test/admin/orders/{$order->id}/refund", [
            'reasonCode' => 'customer-request',
            'reasonNote' => 'Cancelled by phone.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    // Recorded, and said to be recorded.
    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);

    $toast = session('inertia.flash_data')['toast'];

    expect($toast['type'])->toBe('warning')
        ->and($toast['message'])->toContain('refund is recorded')
        // The follow-up failure is named, but nothing of the provider's
        // payload leaks into a message an operator reads.
        ->and($toast['message'])->not->toContain('the consumer blew up');

    // The audit row is the point: who sent the money back is not conditional
    // on whether a downstream listener behaved.
    $audit = AuditLogEntry::query()->where('action', 'commerce.order.refunded')->sole();

    expect($audit->actor_user_id)->toBe((string) $admin->id)
        ->and($audit->subject_id)->toBe($order->id)
        ->and($audit->context['announcement_failed'])->toBeTrue();
});

it('403s a refund without the issue-refunds capability', function (): void {
    // Deliberately not the booking capability: taking money in and giving
    // it back are different decisions (ADR-0013, SPEC.md "Capabilities").
    [, $admin] = commerceOperator(['platform.access-admin', 'commerce.view-orders', 'commerce.book-manual-payment']);

    $this->withBrand();
    $order = Order::factory()->paid()->create();

    $this->actingAs($admin)
        ->post("http://admin.commerce.test/admin/orders/{$order->id}/refund", [
            'reasonCode' => 'customer-request',
        ])
        ->assertForbidden();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});
