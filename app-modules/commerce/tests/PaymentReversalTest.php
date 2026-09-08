<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Commerce\Actions\RecordPaymentReversal;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Events\CreditNoteIssued;
use Konventix\Commerce\Events\OrderLineReversed;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Events\PaymentReversalRecorded;
use Konventix\Commerce\Events\PaymentReversed;
use Konventix\Commerce\Models\CheckedInAdmission;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\RepaymentWindow;
use Konventix\Platform\Brands\BrandContext;

/**
 * The reversal record itself (ADR-0057 point 1): the row that carries the
 * provider's reference, reason and fee, unique per `(provider,
 * provider_reference)` so a replayed notification converges the way
 * ADR-0039 point 3 makes payments converge.
 */
function paymentReversalTestPaid(): array
{
    $order = Order::factory()->create([
        'status' => OrderStatus::Paid,
        'total_gross' => '119.00',
        'currency' => 'EUR',
        'paid_at' => now(),
    ]);

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'amount' => '119.00',
        'currency' => 'EUR',
        'provider_transaction_id' => 'pi_test_1',
    ]);

    return [$order, $payment];
}

/**
 * #236 fix round 1, review finding 1: `RecordPaymentReversal` wraps
 * `RecordRefund` in a transaction of its own, so dispatching "after
 * `RecordRefund`'s own transaction" still ran the consumers inside the outer
 * one — a consumer that threw rolled back the credit note, the `refunded`
 * status *and* the `payment_reversals` row. On the ADR-0039 webhook path,
 * where no brand is ever resolved, that is the likeliest place for the
 * BrandScope trap to fire, so the guarantee has to hold here too.
 */
it('keeps an external refund recorded when a consumer throws, reversal row included (#236)', function (): void {
    $this->withBrand();
    [$order, $payment] = paymentReversalTestPaid();

    Invoice::factory()->create(['order_id' => $order->id]);

    Event::listen(OrderRefunded::class, function (): void {
        throw new RuntimeException('the external-refund consumer blew up');
    });

    expect(fn () => paymentReversalTestRecord(
        $payment,
        ReversalKind::ExternalRefund,
        reference: 're_relay_1',
    ))->toThrow(RuntimeException::class, 'the external-refund consumer blew up');

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(PaymentReversal::query()->where('provider_reference', 're_relay_1')->count())->toBe(1);
});

it('stores a reversal with its casts and both relations', function (): void {
    $this->withBrand();
    [$order, $payment] = paymentReversalTestPaid();

    $reversal = PaymentReversal::factory()->create([
        'payment_id' => $payment->id,
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'kind' => ReversalKind::Return,
        'status' => ReversalStatus::Final,
        'amount' => '119.00',
        'currency' => 'EUR',
        'provider_reference' => 'dp_test_1',
        'reason_code' => 'insufficient_funds',
        'fee_amount' => '15.00',
        'occurred_at' => now(),
        'finalized_at' => now(),
        'repay_due_on' => now()->addDays(7),
    ]);

    $fresh = PaymentReversal::withoutBrandScope()->findOrFail($reversal->id);

    expect($fresh->kind)->toBe(ReversalKind::Return)
        ->and($fresh->status)->toBe(ReversalStatus::Final)
        ->and((string) $fresh->amount)->toBe('119.00')
        ->and((string) $fresh->fee_amount)->toBe('15.00')
        ->and($fresh->occurred_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($fresh->repay_due_on)->toBeInstanceOf(CarbonInterface::class)
        ->and($fresh->brand_id)->toBe($payment->brand_id)
        ->and($fresh->payment()->withoutGlobalScopes()->first()?->id)->toBe($payment->id)
        ->and($fresh->order()->withoutGlobalScopes()->first()?->id)->toBe($order->id);
});

it('refuses a second row for the same provider reference', function (): void {
    $this->withBrand();
    [$order, $payment] = paymentReversalTestPaid();

    PaymentReversal::factory()->create([
        'payment_id' => $payment->id,
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'provider_reference' => 'dp_test_1',
    ]);

    expect(fn () => PaymentReversal::factory()->create([
        'payment_id' => $payment->id,
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'provider_reference' => 'dp_test_1',
    ]))->toThrow(QueryException::class);
});

it('finds a reversal by provider reference without a resolved brand', function (): void {
    $this->withBrand();
    [$order, $payment] = paymentReversalTestPaid();

    $reversal = PaymentReversal::factory()->create([
        'payment_id' => $payment->id,
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_MOLLIE,
        'provider_reference' => 'chb_test_1',
    ]);

    // The api surface resolves no brand (ADR-0010); the finder must still
    // see the row.
    app(BrandContext::class)->forget();

    expect(PaymentReversal::forProviderReference(SettlementAccount::PROVIDER_MOLLIE, 'chb_test_1')?->id)
        ->toBe($reversal->id)
        ->and(PaymentReversal::forProviderReference(SettlementAccount::PROVIDER_STRIPE, 'chb_test_1'))
        ->toBeNull();
});

it('carries the reversed payment and order status the enums gained', function (): void {
    expect(PaymentStatus::Reversed->value)->toBe('reversed')
        ->and(OrderStatus::PaymentReversed->value)->toBe('payment_reversed');
});

it('puts the repayment deadline seven days after the reversal', function (): void {
    $brand = $this->withBrand();
    $window = app(RepaymentWindow::class);

    expect($window->daysFor($brand->id))->toBe(7)
        ->and($window->dueOnFrom(Carbon::parse('2026-09-07 14:30:00'), $brand->id)->toDateString())
        ->toBe('2026-09-14');
});

/**
 * `RecordPaymentReversal` (ADR-0057 point 2 and 3): the sibling of
 * `RecordPaymentSuccess`. Every path is idempotent under the unique key
 * and a row lock, because every provider replays.
 */
function paymentReversalTestRecord(
    Payment $payment,
    ReversalKind $kind = ReversalKind::Return,
    ReversalStatus $status = ReversalStatus::Final,
    string $amount = '119.00',
    string $reference = 'dp_test_1',
    ?string $reasonCode = 'insufficient_funds',
    ?string $feeAmount = null,
): PaymentReversal {
    return app(RecordPaymentReversal::class)->handle(
        payment: $payment,
        kind: $kind,
        status: $status,
        amount: $amount,
        currency: 'EUR',
        providerReference: $reference,
        reasonCode: $reasonCode,
        feeAmount: $feeAmount,
        occurredAt: Carbon::parse('2026-09-07 09:00:00'),
    );
}

it('revives the receivable on a whole-amount final return', function (): void {
    $this->withBrand();
    [$order, $payment] = paymentReversalTestPaid();

    Event::fake([PaymentReversed::class, PaymentReversalRecorded::class, OrderLineReversed::class]);

    $reversal = paymentReversalTestRecord($payment, feeAmount: '15.00');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Reversed)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed)
        // The sale stands: the invoice is untouched and `paid_at` still
        // records that the money once arrived (ADR-0057 point 3).
        ->and($order->paid_at)->not->toBeNull()
        ->and($reversal->repay_due_on?->toDateString())->toBe('2026-09-14')
        ->and($reversal->finalized_at)->not->toBeNull()
        ->and((string) $reversal->fee_amount)->toBe('15.00');

    Event::assertDispatched(
        PaymentReversed::class,
        fn (PaymentReversed $e): bool => $e->reversalId === $reversal->id
            && $e->paymentId === $payment->id
            && $e->orderId === $order->id
            && $e->settlesType === $order->settles_type
            && $e->kind === 'return'
            && $e->amount === '119.00'
            && $e->settlementMethod === 'stripe'
            && $e->providerReference === 'dp_test_1'
            && $e->reasonCode === 'insufficient_funds'
            && $e->repayDueOn === '2026-09-14',
    );
    Event::assertDispatched(PaymentReversalRecorded::class);
    // A direct registration order has one thing to unsettle and
    // `PaymentReversed` already says which.
    Event::assertNotDispatched(OrderLineReversed::class);
});

it('converges on one row and one PaymentReversed when the notification replays', function (): void {
    $this->withBrand();
    [$order, $payment] = paymentReversalTestPaid();

    Event::fake([PaymentReversed::class, PaymentReversalRecorded::class]);

    foreach (range(1, 3) as $attempt) {
        paymentReversalTestRecord($payment->refresh());
    }

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertDispatchedTimes(PaymentReversed::class, 1);
    Event::assertDispatchedTimes(PaymentReversalRecorded::class, 1);
});

it('reverses each registration line of a cart order', function (): void {
    $this->withBrand();

    $order = Order::factory()->paid()->create([
        'settles_type' => 'cart',
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);
    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'amount' => '119.00',
        'currency' => 'EUR',
    ]);

    OrderLine::factory()->create([
        'order_id' => $order->id, 'position' => 1,
        'settles_kind' => 'registration', 'settles_ref' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ]);
    OrderLine::factory()->create([
        'order_id' => $order->id, 'position' => 2,
        'settles_kind' => 'product', 'settles_ref' => '01ARZ3NDEKTSV4RRFFQ69G5FAW',
    ]);

    Event::fake([PaymentReversed::class, OrderLineReversed::class]);

    paymentReversalTestRecord($payment);

    Event::assertDispatchedTimes(OrderLineReversed::class, 1);
    Event::assertDispatched(
        OrderLineReversed::class,
        fn (OrderLineReversed $e): bool => $e->kind === 'registration'
            && $e->refId === '01ARZ3NDEKTSV4RRFFQ69G5FAV'
            && $e->orderRequestKey === $order->request_key,
    );
});

it('keeps an open dispute inside the module and finalises it on loss', function (): void {
    $this->withBrand();
    [$order, $payment] = paymentReversalTestPaid();

    Event::fake([PaymentReversed::class, PaymentReversalRecorded::class]);

    $opened = paymentReversalTestRecord($payment, ReversalKind::Dispute, ReversalStatus::Open);

    expect($opened->status)->toBe(ReversalStatus::Open)
        ->and($opened->finalized_at)->toBeNull()
        ->and($opened->repay_due_on)->toBeNull()
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertNotDispatched(PaymentReversed::class);
    Event::assertDispatchedTimes(PaymentReversalRecorded::class, 1);

    $lost = paymentReversalTestRecord($payment->refresh(), ReversalKind::Dispute, ReversalStatus::Final);

    expect($lost->id)->toBe($opened->id)
        ->and($lost->status)->toBe(ReversalStatus::Final)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertDispatchedTimes(PaymentReversed::class, 1);
    Event::assertDispatchedTimes(PaymentReversalRecorded::class, 2);
});

it('leaves the money where it is when a dispute is withdrawn, and never moves again', function (): void {
    $this->withBrand();
    [$order, $payment] = paymentReversalTestPaid();

    Event::fake([PaymentReversed::class]);

    paymentReversalTestRecord($payment, ReversalKind::Dispute, ReversalStatus::Open);
    $won = paymentReversalTestRecord($payment->refresh(), ReversalKind::Dispute, ReversalStatus::Withdrawn);

    expect($won->status)->toBe(ReversalStatus::Withdrawn)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);

    // A late "lost" for a dispute the organizer already won must not
    // un-decide it.
    $late = paymentReversalTestRecord($payment->refresh(), ReversalKind::Dispute, ReversalStatus::Final);

    expect($late->status)->toBe(ReversalStatus::Withdrawn)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertNotDispatched(PaymentReversed::class);
});

it('records a partial reversal as a row and nothing else', function (): void {
    $this->withBrand();
    [$order, $payment] = paymentReversalTestPaid();

    Event::fake([PaymentReversed::class, PaymentReversalRecorded::class]);

    $reversal = paymentReversalTestRecord($payment, amount: '19.00');

    expect($reversal->status)->toBe(ReversalStatus::Final)
        ->and((string) $reversal->amount)->toBe('19.00')
        // No amount the order could be settled *for* exists until #35.
        ->and($reversal->repay_due_on)->toBeNull()
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertNotDispatched(PaymentReversed::class);
    Event::assertDispatchedTimes(PaymentReversalRecorded::class, 1);
});

it('leaves an order that is no longer paid alone', function (): void {
    $this->withBrand();
    [$order, $payment] = paymentReversalTestPaid();
    $order->forceFill(['status' => OrderStatus::Refunded])->save();

    Event::fake([PaymentReversed::class]);

    paymentReversalTestRecord($payment);

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        // The payment still says the money left; only the order is spoken
        // for already.
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Reversed);

    Event::assertDispatched(PaymentReversed::class);
});

it('reverses an order whose admission was already checked in', function (): void {
    $this->withBrand();
    $registrationId = (string) Str::ulid();

    $order = Order::factory()->paid()->create([
        'settles_type' => 'registration',
        'settles_id' => $registrationId,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);
    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id, 'amount' => '119.00', 'currency' => 'EUR',
    ]);

    CheckedInAdmission::create([
        'admission_id' => (string) Str::ulid(),
        'registration_id' => $registrationId,
        'first_checked_in_at' => now(),
    ]);

    Event::fake([PaymentReversed::class]);

    // ADR-0057 point 4: attendance does not stop the reversal — it only
    // stops the *sweep* from cancelling the order later. The receivable is
    // real either way, so this Action never consults `CheckedInAdmission`.
    paymentReversalTestRecord($payment);

    expect($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertDispatched(PaymentReversed::class);
});

it('undoes the sale for a refund the operator made in the provider dashboard', function (): void {
    $this->withBrand();
    [$order, $payment] = paymentReversalTestPaid();
    Invoice::factory()->create(['order_id' => $order->id]);

    Event::fake([OrderRefunded::class, PaymentReversed::class, CreditNoteIssued::class]);

    $reversal = paymentReversalTestRecord($payment, ReversalKind::ExternalRefund, reference: 're_test_1');

    expect($reversal->kind)->toBe(ReversalKind::ExternalRefund)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);

    // The reaction is the refund's, not the receivable's (ADR-0057 point 1).
    Event::assertNotDispatched(PaymentReversed::class);
    Event::assertDispatched(
        OrderRefunded::class,
        fn (OrderRefunded $e): bool => $e->orderId === $order->id
            && $e->whole === true
            && $e->amountRefunded === '119.00',
    );
});

it('converges on one credit note when an external refund replays', function (): void {
    $this->withBrand();
    [$order, $payment] = paymentReversalTestPaid();
    Invoice::factory()->create(['order_id' => $order->id]);

    Event::fake([OrderRefunded::class]);

    paymentReversalTestRecord($payment, ReversalKind::ExternalRefund, reference: 're_test_1');
    paymentReversalTestRecord($payment->refresh(), ReversalKind::ExternalRefund, reference: 're_test_1');

    expect(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1)
        ->and(PaymentReversal::withoutBrandScope()->count())->toBe(1);

    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('refunds an uninvoiced order without paperwork', function (): void {
    $this->withBrand();
    [$order, $payment] = paymentReversalTestPaid();

    Event::fake([OrderRefunded::class]);

    paymentReversalTestRecord($payment, ReversalKind::ExternalRefund, reference: 're_test_2');

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(0);

    Event::assertDispatched(
        OrderRefunded::class,
        fn (OrderRefunded $e): bool => $e->creditNoteId === null,
    );
});
