<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Settlement\ManualSettlementAdapter;
use Konventix\Commerce\Settlement\NullSettlementAdapter;

it('creates an order that settles a foreign entity by plain ULID', function (): void {
    $brand = $this->withBrand();

    $registrationId = (string) Str::ulid();
    $order = Order::factory()->settles('registration', $registrationId)->create();

    expect($order->brand_id)->toBe($brand->id)
        ->and($order->settles_type)->toBe('registration')
        ->and($order->settles_id)->toBe($registrationId)
        ->and($order->status)->toBe(OrderStatus::Pending);
});

it('marks an order paid through the manual settlement adapter and emits OrderPaid with the SPEC payload', function (): void {
    $this->withBrand();

    $order = Order::factory()->create();

    Event::fake([OrderPaid::class]);

    $accountId = (string) Str::ulid();
    $paid = (new ManualSettlementAdapter)->markOrderPaid($accountId, $order, '42.00', 'EUR', 'bank_transfer');

    expect($paid->status)->toBe(OrderStatus::Paid)
        ->and($paid->paid_at)->not->toBeNull();

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $event): bool => $event->orderId === $order->id
        && $event->brandId === $order->brand_id
        && $event->settlesType === $order->settles_type
        && $event->settlesId === $order->settles_id
        && $event->amountPaid === '42.00'
        && $event->currency === 'EUR'
        && $event->settlementMethod === 'bank_transfer');
});

it('leaves the order untouched and emits nothing through the null adapter', function (): void {
    $this->withBrand();

    $order = Order::factory()->create();

    Event::fake([OrderPaid::class]);

    $result = (new NullSettlementAdapter)->markOrderPaid((string) Str::ulid(), $order, '42.00', 'EUR', 'manual');

    expect($result->status)->toBe(OrderStatus::Pending)
        ->and($result->paid_at)->toBeNull();

    Event::assertNotDispatched(OrderPaid::class);
});
