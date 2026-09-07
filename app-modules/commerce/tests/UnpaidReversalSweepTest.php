<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Commerce\Actions\CancelOrderForUnpaidReversal;
use Konventix\Commerce\Actions\IssueCreditNote;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Events\CreditNoteIssued;
use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Events\OrderLineCancelled;
use Konventix\Commerce\Models\CheckedInAdmission;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Models\SettlementAccount;

/**
 * `commerce:cancel-unpaid-reversals` (ADR-0057 point 4): the deadline the
 * reversal row carries, and what happens when it passes. The order is
 * cancelled and the invoice corrected by a credit note — unless someone
 * already attended, in which case the money is a receivable and the
 * ordinary dunning ladder takes it from its missed deadline.
 *
 * Helper names prefixed: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
/**
 * @return array{0: Order, 1: PaymentReversal}
 */
function sweepTestReversedOrder(
    int $dueDaysAgo = 1,
    bool $invoiced = true,
    string $settlesType = 'registration',
    ReversalKind $kind = ReversalKind::Return,
    ReversalStatus $status = ReversalStatus::Final,
): array {
    $order = Order::factory()->paid()->create([
        'settles_type' => $settlesType,
        'settles_id' => (string) Str::ulid(),
    ]);
    $order->forceFill(['status' => OrderStatus::PaymentReversed])->save();

    if ($invoiced) {
        Invoice::factory()->create(['order_id' => $order->id]);
    }

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'amount' => (string) $order->total_gross,
        'currency' => $order->currency,
    ]);

    $reversal = PaymentReversal::factory()->create([
        'payment_id' => $payment->id,
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'provider_reference' => 'chb_'.Str::random(10),
        'kind' => $kind,
        'status' => $status,
        'amount' => (string) $order->total_gross,
        'currency' => $order->currency,
        'occurred_at' => now()->subDays($dueDaysAgo + 7),
        'finalized_at' => now()->subDays($dueDaysAgo + 7),
        'repay_due_on' => now()->subDays($dueDaysAgo)->startOfDay(),
    ]);

    return [$order, $reversal];
}

function sweepTestCheckIn(Order $order, string $registrationId): void
{
    // `brand_id` is stamped by BelongsToBrand from the ambient brand the
    // test set with `withBrand()` — the projection's own writer does the
    // same (`ProjectCheckedInAdmission`).
    CheckedInAdmission::query()->create([
        'admission_id' => (string) Str::ulid(),
        'registration_id' => $registrationId,
        'first_checked_in_at' => now()->subDay(),
    ]);
}

it('cancels an order whose reversed payment went unpaid past the deadline', function (): void {
    $this->withBrand();
    [$order] = sweepTestReversedOrder();

    Event::fake([OrderCancelled::class, CreditNoteIssued::class]);

    $this->artisan('commerce:cancel-unpaid-reversals')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);

    $creditNote = CreditNote::withoutBrandScope()->where('order_id', $order->id)->sole();

    expect($creditNote->reason_code)->toBe(IssueCreditNote::REASON_CANCELLATION)
        ->and(IssueCreditNote::REASON_CANCELLATION)->toBe('cancellation');

    Event::assertDispatched(
        CreditNoteIssued::class,
        fn (CreditNoteIssued $e): bool => $e->orderId === $order->id
            && $e->reasonCode === IssueCreditNote::REASON_CANCELLATION,
    );

    Event::assertDispatched(
        OrderCancelled::class,
        fn (OrderCancelled $e): bool => $e->orderId === $order->id
            && $e->reasonCode === CancelOrderForUnpaidReversal::REASON_REVERSAL_UNPAID
            && $e->cancelledByUserId === null
            && $e->creditNoteId === $creditNote->id,
    );
});

it('leaves a deadline that has not passed alone', function (): void {
    $this->withBrand();
    [$order] = sweepTestReversedOrder(dueDaysAgo: -3);

    Event::fake([OrderCancelled::class]);

    $this->artisan('commerce:cancel-unpaid-reversals')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::PaymentReversed)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0);

    Event::assertNotDispatched(OrderCancelled::class);
});

it('cancels an uninvoiced order without a credit note', function (): void {
    $this->withBrand();
    [$order] = sweepTestReversedOrder(invoiced: false);

    Event::fake([OrderCancelled::class]);

    $this->artisan('commerce:cancel-unpaid-reversals')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0);

    Event::assertDispatched(
        OrderCancelled::class,
        fn (OrderCancelled $e): bool => $e->orderId === $order->id && $e->creditNoteId === null,
    );
});

it('keeps an order whose admission was already used, and hands its invoice to dunning', function (): void {
    $this->withBrand();
    [$order, $reversal] = sweepTestReversedOrder();

    sweepTestCheckIn($order, $order->settles_id);

    Event::fake([OrderCancelled::class]);

    $this->artisan('commerce:cancel-unpaid-reversals')->assertSuccessful();

    $invoice = Invoice::withoutBrandScope()->where('order_id', $order->id)->sole();

    expect($order->refresh()->status)->toBe(OrderStatus::PaymentReversed)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0)
        ->and($invoice->overdue_at)->not->toBeNull()
        ->and($invoice->overdue_at->toDateString())->toBe($reversal->repay_due_on->toDateString());

    Event::assertNotDispatched(OrderCancelled::class);
});

it('cancels a cart order line by line so registration knows which seats to release', function (): void {
    $this->withBrand();
    [$order] = sweepTestReversedOrder(settlesType: 'cart');

    $firstRegistration = (string) Str::ulid();
    $secondRegistration = (string) Str::ulid();

    OrderLine::factory()->forOrder($order)->create([
        'position' => 1,
        'settles_kind' => 'registration',
        'settles_ref' => $firstRegistration,
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'position' => 2,
        'settles_kind' => 'registration',
        'settles_ref' => $secondRegistration,
    ]);
    OrderLine::factory()->forOrder($order)->create([
        'position' => 3,
        'settles_kind' => 'product',
        'settles_ref' => (string) Str::ulid(),
    ]);

    Event::fake([OrderCancelled::class, OrderLineCancelled::class]);

    $this->artisan('commerce:cancel-unpaid-reversals')->assertSuccessful();

    Event::assertDispatchedTimes(OrderLineCancelled::class, 2);

    foreach ([$firstRegistration, $secondRegistration] as $registrationId) {
        Event::assertDispatched(
            OrderLineCancelled::class,
            fn (OrderLineCancelled $e): bool => $e->refId === $registrationId
                && $e->kind === 'registration'
                && $e->reasonCode === CancelOrderForUnpaidReversal::REASON_REVERSAL_UNPAID
                && $e->orderRequestKey === $order->request_key,
        );
    }
});

it('emits no line events for a direct registration order', function (): void {
    $this->withBrand();
    [$order] = sweepTestReversedOrder();

    OrderLine::factory()->forOrder($order)->create([
        'position' => 1,
        'settles_kind' => 'registration',
        'settles_ref' => $order->settles_id,
    ]);

    Event::fake([OrderCancelled::class, OrderLineCancelled::class]);

    $this->artisan('commerce:cancel-unpaid-reversals')->assertSuccessful();

    Event::assertDispatched(OrderCancelled::class);
    Event::assertNotDispatched(OrderLineCancelled::class);
});

it('is a no-op on a second run', function (): void {
    $this->withBrand();
    [$order] = sweepTestReversedOrder();

    $this->artisan('commerce:cancel-unpaid-reversals')->assertSuccessful();

    Event::fake([OrderCancelled::class, CreditNoteIssued::class]);

    $this->artisan('commerce:cancel-unpaid-reversals')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);

    Event::assertNotDispatched(OrderCancelled::class);
    Event::assertNotDispatched(CreditNoteIssued::class);
});

it('ignores a withdrawn dispute and an external refund', function (): void {
    $this->withBrand();
    [$won] = sweepTestReversedOrder(kind: ReversalKind::Dispute, status: ReversalStatus::Withdrawn);
    [$dashboardRefund] = sweepTestReversedOrder(kind: ReversalKind::ExternalRefund);

    Event::fake([OrderCancelled::class]);

    $this->artisan('commerce:cancel-unpaid-reversals')->assertSuccessful();

    expect($won->refresh()->status)->toBe(OrderStatus::PaymentReversed)
        ->and($dashboardRefund->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertNotDispatched(OrderCancelled::class);
});

it('refuses to cancel an order that is not payment_reversed', function (): void {
    $this->withBrand();

    $order = Order::factory()->paid()->create(['settles_type' => 'registration']);

    expect(fn () => app(CancelOrderForUnpaidReversal::class)->handle($order))
        ->toThrow(RuntimeException::class);
});

it('names the operator who cancelled by hand', function (): void {
    $this->withBrand();
    [$order] = sweepTestReversedOrder();

    $userId = (string) Str::ulid();

    Event::fake([OrderCancelled::class]);

    app(CancelOrderForUnpaidReversal::class)->handle($order, $userId);

    Event::assertDispatched(
        OrderCancelled::class,
        fn (OrderCancelled $e): bool => $e->cancelledByUserId === $userId,
    );
});
