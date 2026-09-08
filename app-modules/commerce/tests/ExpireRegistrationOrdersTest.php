<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Commerce\Actions\ExpireRegistrationOrders;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\PaymentUnapplied;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Platform\Models\Brand;

/**
 * `commerce:expire-registration-orders` (#131): a direct `instant`
 * registration order — the one a brand without `commerce:shop` still
 * places straight from the settlement request — sat `pending` forever when
 * nobody paid, holding its quota, because only `commerce:expire-carts`
 * ever swept anything. The window is the carts' own checkout grace, and
 * the cancellation is the `OrderCancelled` Registration already consumes.
 *
 * Helper names prefixed: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
/**
 * A payment window is this file's whole subject, and every arrangement
 * back-dates `created_at` relative to `now()` while the sweep evaluates
 * `now()` again at run time — a sixty-second margin in both directions, and
 * the "inside the window" case flipped to a failure if a test body ever took
 * a minute (whole-branch review finding 14). A whole second, so no `timestamp(0)`
 * column can round a boundary case across the line.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-08 12:00:00'));
});

/**
 * A live, offered Mollie account — the brand can put a payer in front of a
 * hosted checkout, which is the population this sweep is about.
 */
function expiryTestHostedAccount(): SettlementAccount
{
    return SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)->create();
}

function expiryTestDirectOrder(
    int $minutesOld = Cart::CHECKOUT_GRACE_MINUTES + 1,
    OrderStatus $status = OrderStatus::Pending,
    string $settlesType = 'registration',
    bool $invoiced = false,
): Order {
    $order = Order::factory()->create([
        'settles_type' => $settlesType,
        'settles_id' => (string) Str::ulid(),
        'status' => $status,
    ]);

    $order->forceFill(['created_at' => now()->subMinutes($minutesOld)])->save();

    if ($invoiced) {
        Invoice::factory()->create(['order_id' => $order->id]);
    }

    return $order;
}

it('cancels a pending direct registration order past the payment window', function (): void {
    $this->withBrand();
    expiryTestHostedAccount();
    $order = expiryTestDirectOrder();

    Event::fake([OrderCancelled::class]);

    $this->artisan('commerce:expire-registration-orders')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);

    Event::assertDispatched(
        OrderCancelled::class,
        fn (OrderCancelled $e): bool => $e->orderId === $order->id
            && $e->brandId === $order->brand_id
            && $e->settlesType === 'registration'
            && $e->settlesId === $order->settles_id
            && $e->reasonCode === ExpireRegistrationOrders::REASON_PAYMENT_WINDOW_EXPIRED
            && $e->cancelledByUserId === null
            && $e->creditNoteId === null,
    );
});

/**
 * Whole-branch review finding 14. What stood here was
 * `expect(WINDOW_MINUTES)->toBe(Cart::CHECKOUT_GRACE_MINUTES)` against a
 * constant declared as `= Cart::CHECKOUT_GRACE_MINUTES` — `X === X`, which
 * could only fail if someone re-typed the constant as a literal, and which
 * said nothing about the sweep using that window. This pins both the window
 * and the `<=` at its boundary: an order placed exactly a window ago is
 * cancelled, one placed a second later is not.
 */
it('cancels at the window boundary and leaves the second inside it alone (#131)', function (): void {
    $this->withBrand();
    expiryTestHostedAccount();

    $due = expiryTestDirectOrder();
    $due->forceFill(['created_at' => now()->subMinutes(ExpireRegistrationOrders::WINDOW_MINUTES)])->save();

    $inside = expiryTestDirectOrder();
    $inside->forceFill([
        'created_at' => now()->subMinutes(ExpireRegistrationOrders::WINDOW_MINUTES)->addSecond(),
    ])->save();

    $this->artisan('commerce:expire-registration-orders')->assertSuccessful();

    expect($due->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($inside->refresh()->status)->toBe(OrderStatus::Pending);
});

it('leaves a pending direct registration order inside the window alone', function (): void {
    $this->withBrand();
    $order = expiryTestDirectOrder(minutesOld: Cart::CHECKOUT_GRACE_MINUTES - 1);

    Event::fake([OrderCancelled::class]);

    $this->artisan('commerce:expire-registration-orders')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    Event::assertNotDispatched(OrderCancelled::class);
});

it('leaves an invoiced registration order to the dunning ladder', function (): void {
    $this->withBrand();
    $order = expiryTestDirectOrder(invoiced: true);

    Event::fake([OrderCancelled::class]);

    $this->artisan('commerce:expire-registration-orders')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    Event::assertNotDispatched(OrderCancelled::class);
});

it('leaves a cart order to the cart sweep', function (): void {
    $this->withBrand();
    $order = expiryTestDirectOrder(settlesType: 'cart');

    Event::fake([OrderCancelled::class]);

    $this->artisan('commerce:expire-registration-orders')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    Event::assertNotDispatched(OrderCancelled::class);
});

it('never touches an order that is no longer pending', function (): void {
    $this->withBrand();
    $paid = expiryTestDirectOrder(status: OrderStatus::Paid);
    $cancelled = expiryTestDirectOrder(status: OrderStatus::Cancelled);
    $reversed = expiryTestDirectOrder(status: OrderStatus::PaymentReversed);

    Event::fake([OrderCancelled::class]);

    $this->artisan('commerce:expire-registration-orders')->assertSuccessful();

    expect($paid->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($cancelled->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($reversed->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertNotDispatched(OrderCancelled::class);
});

it('records a late payment on an expired order as unapplied, exactly as for a cart (#6)', function (): void {
    $this->withBrand();
    expiryTestHostedAccount();
    $order = expiryTestDirectOrder();

    $this->artisan('commerce:expire-registration-orders')->assertSuccessful();

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'amount' => (string) $order->total_gross,
        'currency' => $order->currency,
    ]);

    Event::fake([OrderPaid::class, PaymentUnapplied::class]);

    app(RecordPaymentSuccess::class)->handle($payment);

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);

    Event::assertNotDispatched(OrderPaid::class);
    Event::assertDispatched(
        PaymentUnapplied::class,
        fn (PaymentUnapplied $e): bool => $e->orderId === $order->id && $e->paymentId === $payment->id,
    );
});

it('is registered in the scheduler on the cart sweep\'s cadence', function (): void {
    $entries = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'commerce:expire-registration-orders'));

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->expression)->toBe('*/15 * * * *');
});

it('leaves a bank-transfer brand\'s registration order alone, however old it is', function (): void {
    $this->withBrand();

    // No settlement account at all: `SettlementAccount`'s own docblock calls
    // this the default, and `pay/Show.vue` tells the payer the details arrive
    // with the confirmation and the transfer takes as long as it takes.
    $order = expiryTestDirectOrder(minutesOld: Cart::CHECKOUT_GRACE_MINUTES * 24 * 7);

    Event::fake([OrderCancelled::class]);

    $this->artisan('commerce:expire-registration-orders')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    Event::assertNotDispatched(OrderCancelled::class);
});

it('leaves an order alone when the brand\'s only account cannot host a checkout', function (): void {
    $this->withBrand();
    SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MANUAL)->create();

    $order = expiryTestDirectOrder();

    Event::fake([OrderCancelled::class]);

    $this->artisan('commerce:expire-registration-orders')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    Event::assertNotDispatched(OrderCancelled::class);
});

it('leaves an order alone once the brand\'s hosted account is disconnected', function (): void {
    $this->withBrand();
    SettlementAccount::factory()->provider(SettlementAccount::PROVIDER_MOLLIE)->disconnected()->create();

    $order = expiryTestDirectOrder();

    Event::fake([OrderCancelled::class]);

    $this->artisan('commerce:expire-registration-orders')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    Event::assertNotDispatched(OrderCancelled::class);
});

it('sweeps one brand at a time when asked to', function (): void {
    $swept = $this->withBrand();
    expiryTestHostedAccount();
    $sweptOrder = expiryTestDirectOrder();

    $other = $this->withBrand(Brand::factory()->create());
    expiryTestHostedAccount();
    $otherOrder = expiryTestDirectOrder();
    $this->withBrand($swept);

    $this->artisan('commerce:expire-registration-orders', ['--brand' => $swept->getKey()])
        ->assertSuccessful();

    expect($sweptOrder->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($otherOrder->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($other->getKey())->not->toBe($swept->getKey());
});
