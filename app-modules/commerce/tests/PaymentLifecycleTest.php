<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Actions\BookManualPayment;
use Konventix\Commerce\Actions\RecordPaymentFailure;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\OrderLineSettled;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\PaymentReceived;
use Konventix\Commerce\Events\PaymentUnapplied;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;

function paymentLifecycleOrder(OrderStatus $status = OrderStatus::Pending): Order
{
    return Order::factory()->create([
        'status' => $status,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);
}

function paymentLifecyclePending(Order $order): Payment
{
    return Payment::factory()->create([
        'order_id' => $order->id,
        'amount' => $order->total_gross,
        'currency' => $order->currency,
    ]);
}

it('settles the order and emits both events on payment success', function (): void {
    $this->withBrand();
    $order = paymentLifecycleOrder();
    $payment = paymentLifecyclePending($order);

    Event::fake([PaymentReceived::class, OrderPaid::class]);

    app(RecordPaymentSuccess::class)->handle($payment);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull();

    Event::assertDispatched(
        PaymentReceived::class,
        fn (PaymentReceived $event): bool => $event->paymentId === $payment->id
            && $event->orderId === $order->id
            && $event->amount === '119.00'
            && $event->externalReference === $payment->external_id,
    );
    Event::assertDispatched(
        OrderPaid::class,
        fn (OrderPaid $event): bool => $event->orderId === $order->id
            && $event->settlementMethod === 'stripe',
    );
});

it('converges on one OrderPaid when the success webhook replays', function (): void {
    $this->withBrand();
    $order = paymentLifecycleOrder();
    $payment = paymentLifecyclePending($order);

    Event::fake([PaymentReceived::class, OrderPaid::class]);

    app(RecordPaymentSuccess::class)->handle($payment);
    app(RecordPaymentSuccess::class)->handle($payment->refresh());

    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Event::assertDispatchedTimes(PaymentReceived::class, 1);
});

it('records a second successful payment without re-emitting OrderPaid', function (): void {
    $this->withBrand();
    $order = paymentLifecycleOrder(OrderStatus::Paid);
    $payment = paymentLifecyclePending($order);

    Event::fake([PaymentReceived::class, OrderPaid::class]);

    app(RecordPaymentSuccess::class)->handle($payment);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);

    Event::assertDispatched(PaymentReceived::class);
    Event::assertNotDispatched(OrderPaid::class);
});

it('marks a failed payment and leaves the order pending', function (): void {
    $this->withBrand();
    $order = paymentLifecycleOrder();
    $payment = paymentLifecyclePending($order);

    Event::fake([PaymentFailed::class, OrderPaid::class]);

    app(RecordPaymentFailure::class)->handle($payment, 'card_declined');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('card_declined')
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);

    Event::assertDispatched(
        PaymentFailed::class,
        fn (PaymentFailed $event): bool => $event->reasonCode === 'card_declined',
    );
    Event::assertNotDispatched(OrderPaid::class);
});

it('marks an expired attempt as expired, not failed', function (): void {
    $this->withBrand();
    $payment = paymentLifecyclePending(paymentLifecycleOrder());

    Event::fake([PaymentFailed::class]);

    app(RecordPaymentFailure::class)->handle($payment, 'session_expired', expired: true);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Expired);
});

it('never demotes a succeeded payment on a stale failure notification', function (): void {
    $this->withBrand();
    $order = paymentLifecycleOrder();
    $payment = paymentLifecyclePending($order);

    app(RecordPaymentSuccess::class)->handle($payment);

    Event::fake([PaymentFailed::class]);

    app(RecordPaymentFailure::class)->handle($payment->refresh(), 'late_failure');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertNotDispatched(PaymentFailed::class);
});

it('records a manual booking as a first-class payment', function (): void {
    $this->withBrand();
    $order = paymentLifecycleOrder();

    Event::fake([PaymentReceived::class, OrderPaid::class]);

    app(BookManualPayment::class)->handle($order);

    $payment = Payment::withoutBrandScope()->where('order_id', $order->id)->sole();

    expect($payment->provider)->toBe('manual')
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->external_id)->toBeNull()
        ->and($payment->settlement_account_id)->toBeNull();

    Event::assertDispatched(
        PaymentReceived::class,
        fn (PaymentReceived $event): bool => $event->settlementMethod === 'manual'
            && $event->externalReference === null,
    );
    Event::assertDispatched(OrderPaid::class);
});

it('settles a payment_reversed order exactly like a pending one', function (): void {
    // ADR-0057 point 3: the money can come back by the pay link, the door
    // POS or a bank transfer, and each of them ends in OrderPaid with
    // nothing new to teach downstream.
    $this->withBrand();
    $order = paymentLifecycleOrder(OrderStatus::PaymentReversed);
    $payment = paymentLifecyclePending($order);

    Event::fake([PaymentReceived::class, OrderPaid::class, PaymentUnapplied::class]);

    app(RecordPaymentSuccess::class)->handle($payment);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull();

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e): bool => $e->orderId === $order->id);
    Event::assertNotDispatched(PaymentUnapplied::class);
});

it('re-emits the line events when a reversed cart order is paid again', function (): void {
    $this->withBrand();

    $order = Order::factory()->create([
        'status' => OrderStatus::PaymentReversed,
        'settles_type' => 'cart',
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);
    OrderLine::factory()->create([
        'order_id' => $order->id,
        'settles_kind' => 'registration',
        'settles_ref' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ]);

    Event::fake([OrderLineSettled::class]);

    app(RecordPaymentSuccess::class)->handle(paymentLifecyclePending($order));

    Event::assertDispatchedTimes(OrderLineSettled::class, 1);
});

it('records a success for a cancelled order as unapplied money', function (): void {
    // ADR-0057 point 9 / #6: the money moved, and a log line is not a
    // domain record. What happens next is the operator's decision.
    $this->withBrand();
    $order = paymentLifecycleOrder(OrderStatus::Cancelled);
    $payment = paymentLifecyclePending($order);

    Event::fake([PaymentReceived::class, OrderPaid::class, PaymentUnapplied::class]);

    app(RecordPaymentSuccess::class)->handle($payment);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled);

    Event::assertNotDispatched(OrderPaid::class);
    Event::assertDispatched(
        PaymentUnapplied::class,
        fn (PaymentUnapplied $e): bool => $e->paymentId === $payment->id
            && $e->orderId === $order->id
            && $e->brandId === $order->brand_id
            && $e->amount === '119.00'
            && $e->currency === 'EUR'
            && $e->provider === 'stripe'
            && $e->settlementAccountId === null,
    );
});

it('emits nothing new when a second payment lands on an already paid order', function (): void {
    $this->withBrand();
    $order = paymentLifecycleOrder(OrderStatus::Paid);

    Event::fake([OrderPaid::class, PaymentUnapplied::class]);

    app(RecordPaymentSuccess::class)->handle(paymentLifecyclePending($order));

    // Duplicate money against a paid order is an operator refund case, not
    // an unapplied payment: the order got what it was owed.
    Event::assertNotDispatched(OrderPaid::class);
    Event::assertNotDispatched(PaymentUnapplied::class);
});

it('finds every unapplied payment through the scope', function (): void {
    $this->withBrand();

    $cancelled = paymentLifecycleOrder(OrderStatus::Cancelled);
    $unapplied = Payment::factory()->succeeded()->create([
        'order_id' => $cancelled->id, 'amount' => '119.00', 'currency' => 'EUR',
    ]);

    // Paid order, succeeded payment: applied.
    $paid = paymentLifecycleOrder(OrderStatus::Paid);
    Payment::factory()->succeeded()->create(['order_id' => $paid->id]);

    // Cancelled order, pending payment: no money ever moved.
    Payment::factory()->create(['order_id' => $cancelled->id]);

    expect(Payment::withoutBrandScope()->unapplied()->pluck('id')->all())->toBe([$unapplied->id]);
});
