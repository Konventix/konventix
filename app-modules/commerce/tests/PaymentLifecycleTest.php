<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Actions\BookManualPayment;
use Konventix\Commerce\Actions\RecordPaymentFailure;
use Konventix\Commerce\Actions\RecordPaymentSuccess;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\PaymentReceived;
use Konventix\Commerce\Models\Order;
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
        fn (PaymentReceived $event): bool => $event->settlementMethod === 'bank_transfer'
            && $event->externalReference === null,
    );
    Event::assertDispatched(OrderPaid::class);
});
