<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\OrderRefunded;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\PaymentReceived;
use Konventix\Commerce\Events\PaymentReversed;
use Konventix\Commerce\Events\PaymentUnapplied;
use Konventix\Commerce\Models\CheckedInAdmission;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;
use Konventix\Commerce\Models\PaymentReversal;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Stripe\StripeWebhookEvents;

/**
 * The Stripe webhook endpoint (ADR-0039): signature verification is real —
 * the tests compute the same HMAC Stripe would — and settlement truth only
 * ever moves through it.
 */
const STRIPE_WEBHOOK_TEST_SECRET = 'whsec_test_secret';

function stripeWebhookTestPending(): array
{
    test()->withBrand();
    config(['services.stripe.webhook_secret' => STRIPE_WEBHOOK_TEST_SECRET]);

    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'external_id' => 'cs_test_abc',
        'amount' => '119.00',
        'currency' => 'EUR',
    ]);

    return [$order, $payment];
}

/**
 * @param  array<string, mixed>  $session
 */
function stripeWebhookTestPost(string $type, array $session, ?string $secret = STRIPE_WEBHOOK_TEST_SECRET): TestResponse
{
    $payload = json_encode([
        'id' => 'evt_test_1',
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => ['object' => 'checkout.session', ...$session]],
    ], JSON_THROW_ON_ERROR);

    $timestamp = time();
    $signature = $secret === null
        ? 'garbage'
        : hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    return test()->call(
        'POST',
        '/api/commerce/webhooks/stripe',
        server: [
            'HTTP_STRIPE_SIGNATURE' => sprintf('t=%d,v1=%s', $timestamp, $signature),
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $payload,
    );
}

it('settles the order on a completed session that is paid', function (): void {
    [$order, $payment] = stripeWebhookTestPending();

    Event::fake([PaymentReceived::class, OrderPaid::class]);

    stripeWebhookTestPost('checkout.session.completed', [
        'id' => 'cs_test_abc',
        'payment_status' => 'paid',
        'payment_intent' => 'pi_test_1',
    ])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->provider_transaction_id)->toBe('pi_test_1')
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e): bool => $e->settlementMethod === 'stripe');
});

it('does not settle on a completed session that is still unpaid', function (): void {
    // Delayed-notification methods: `completed` arrives while unpaid; the
    // decision comes later as async_payment_succeeded/failed.
    [$order, $payment] = stripeWebhookTestPending();

    Event::fake([OrderPaid::class]);

    stripeWebhookTestPost('checkout.session.completed', [
        'id' => 'cs_test_abc',
        'payment_status' => 'unpaid',
        'payment_intent' => 'pi_test_1',
    ])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        // The money identifier is still recorded for the eventual refund.
        ->and($payment->provider_transaction_id)->toBe('pi_test_1')
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);

    Event::assertNotDispatched(OrderPaid::class);
});

it('settles on the async success that follows an unpaid completion', function (): void {
    [$order, $payment] = stripeWebhookTestPending();

    stripeWebhookTestPost('checkout.session.completed', [
        'id' => 'cs_test_abc', 'payment_status' => 'unpaid', 'payment_intent' => 'pi_test_1',
    ])->assertOk();

    stripeWebhookTestPost('checkout.session.async_payment_succeeded', [
        'id' => 'cs_test_abc', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_1',
    ])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('marks the payment failed on async failure and leaves the order pending', function (): void {
    [$order, $payment] = stripeWebhookTestPending();

    Event::fake([PaymentFailed::class]);

    stripeWebhookTestPost('checkout.session.async_payment_failed', [
        'id' => 'cs_test_abc', 'payment_status' => 'unpaid',
    ])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);

    Event::assertDispatched(PaymentFailed::class);
});

it('marks an expired session as expired', function (): void {
    [, $payment] = stripeWebhookTestPending();

    stripeWebhookTestPost('checkout.session.expired', [
        'id' => 'cs_test_abc', 'payment_status' => 'unpaid',
    ])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Expired);
});

it('rejects a payload whose signature does not verify', function (): void {
    [$order, $payment] = stripeWebhookTestPending();

    stripeWebhookTestPost('checkout.session.completed', [
        'id' => 'cs_test_abc', 'payment_status' => 'paid',
    ], secret: null)->assertStatus(400);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('acknowledges sessions and event types it does not know', function (): void {
    // Non-2xx makes Stripe retry for days; "not ours" is not fixed by retry.
    stripeWebhookTestPending();

    stripeWebhookTestPost('checkout.session.completed', [
        'id' => 'cs_unknown', 'payment_status' => 'paid',
    ])->assertOk();

    stripeWebhookTestPost('payment_intent.created', ['id' => 'pi_x'])->assertOk();
});

it('converges when Stripe replays the success notification', function (): void {
    [, $payment] = stripeWebhookTestPending();

    Event::fake([OrderPaid::class, PaymentReceived::class]);

    foreach (range(1, 3) as $attempt) {
        stripeWebhookTestPost('checkout.session.completed', [
            'id' => 'cs_test_abc', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_1',
        ])->assertOk();
    }

    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Event::assertDispatchedTimes(PaymentReceived::class, 1);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
});

/**
 * The reversal half of the same endpoint (ADR-0057 point 7): Stripe maps
 * from the signed event, correlated through `provider_transaction_id` —
 * the payment intent recorded at settlement.
 */
function stripeWebhookTestSettled(string $settlesType = 'registration'): array
{
    test()->withBrand();
    config(['services.stripe.webhook_secret' => STRIPE_WEBHOOK_TEST_SECRET]);

    $order = Order::factory()->paid()->create([
        'settles_type' => $settlesType,
        'total_gross' => '119.00',
        'currency' => 'EUR',
    ]);

    $payment = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'provider' => SettlementAccount::PROVIDER_STRIPE,
        'external_id' => 'cs_test_settled',
        'provider_transaction_id' => 'pi_test_settled',
        'amount' => '119.00',
        'currency' => 'EUR',
    ]);

    return [$order, $payment];
}

/**
 * `$eventCreated` is the *notification's* own timestamp, which is what says
 * when a dispute closed — the dispute object's `created` is its birth,
 * weeks earlier for a card dispute.
 *
 * @param  array<string, mixed>  $object
 */
function stripeWebhookTestPostObject(
    string $type,
    string $objectName,
    array $object,
    ?string $eventCreated = null,
): TestResponse {
    $payload = json_encode([
        'id' => 'evt_test_2',
        'object' => 'event',
        'type' => $type,
        'created' => Carbon::parse($eventCreated ?? '2026-09-07 09:00:00')->getTimestamp(),
        'data' => ['object' => ['object' => $objectName, ...$object]],
    ], JSON_THROW_ON_ERROR);

    $timestamp = time();

    return test()->call(
        'POST',
        '/api/commerce/webhooks/stripe',
        server: [
            'HTTP_STRIPE_SIGNATURE' => sprintf(
                't=%d,v1=%s',
                $timestamp,
                hash_hmac('sha256', $timestamp.'.'.$payload, STRIPE_WEBHOOK_TEST_SECRET),
            ),
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $payload,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function stripeWebhookTestDispute(string $status, array $overrides = []): array
{
    return [
        'id' => 'dp_test_1',
        'amount' => 11900,
        'currency' => 'eur',
        'charge' => 'ch_test_1',
        'payment_intent' => 'pi_test_settled',
        'reason' => 'insufficient_funds',
        'status' => $status,
        'created' => Carbon::parse('2026-09-07 09:00:00')->getTimestamp(),
        'balance_transactions' => [['fee' => 1500]],
        ...$overrides,
    ];
}

it('revives the receivable on a dispute that is born lost', function (): void {
    // Stripe's shape for a SEPA debit return: no evidence can be filed, so
    // the dispute closes as it opens (ADR-0057 point 1).
    [$order, $payment] = stripeWebhookTestSettled();

    Event::fake([PaymentReversed::class]);

    stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('lost'))
        ->assertOk();

    $reversal = PaymentReversal::withoutBrandScope()->sole();

    expect($reversal->kind)->toBe(ReversalKind::Return)
        ->and($reversal->status)->toBe(ReversalStatus::Final)
        ->and((string) $reversal->amount)->toBe('119.00')
        ->and((string) $reversal->fee_amount)->toBe('15.00')
        ->and($reversal->reason_code)->toBe('insufficient_funds')
        ->and($reversal->repay_due_on?->toDateString())->toBe('2026-09-14')
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Reversed)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertDispatched(
        PaymentReversed::class,
        fn (PaymentReversed $e): bool => $e->orderId === $order->id
            && $e->kind === 'return'
            && $e->amount === '119.00'
            && $e->providerReference === 'dp_test_1'
            && $e->repayDueOn === '2026-09-14',
    );
});

it('holds an opened dispute inside the module until Stripe decides', function (): void {
    [$order, $payment] = stripeWebhookTestSettled();

    Event::fake([PaymentReversed::class]);

    stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('needs_response'))
        ->assertOk();

    $reversal = PaymentReversal::withoutBrandScope()->sole();

    expect($reversal->kind)->toBe(ReversalKind::Dispute)
        ->and($reversal->status)->toBe(ReversalStatus::Open)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertNotDispatched(PaymentReversed::class);
});

it('withdraws the reversal when the organizer wins the dispute', function (): void {
    [$order, $payment] = stripeWebhookTestSettled();

    Event::fake([PaymentReversed::class]);

    stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('under_review'))
        ->assertOk();
    stripeWebhookTestPostObject('charge.dispute.closed', 'dispute', stripeWebhookTestDispute('won'))
        ->assertOk();

    expect(PaymentReversal::withoutBrandScope()->sole()->status)->toBe(ReversalStatus::Withdrawn)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    Event::assertNotDispatched(PaymentReversed::class);
});

it('reverses the payment when the organizer loses the dispute', function (): void {
    [$order, $payment] = stripeWebhookTestSettled();

    Event::fake([PaymentReversed::class]);

    stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('under_review'))
        ->assertOk();
    stripeWebhookTestPostObject('charge.dispute.closed', 'dispute', stripeWebhookTestDispute('lost'))
        ->assertOk();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(1)
        ->and(PaymentReversal::withoutBrandScope()->sole()->status)->toBe(ReversalStatus::Final)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Reversed)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertDispatchedTimes(PaymentReversed::class, 1);
});

it('records a refund made in the Stripe dashboard as an external refund', function (): void {
    [$order] = stripeWebhookTestSettled();
    Invoice::factory()->create(['order_id' => $order->id]);

    Event::fake([OrderRefunded::class]);

    stripeWebhookTestPostObject('charge.refunded', 'charge', [
        'id' => 'ch_test_1',
        'payment_intent' => 'pi_test_settled',
        'amount' => 11900,
        'amount_refunded' => 11900,
        'currency' => 'eur',
        'created' => Carbon::parse('2026-09-07 09:00:00')->getTimestamp(),
        'refunds' => ['object' => 'list', 'data' => [['id' => 're_test_1', 'reason' => 'requested_by_customer']]],
    ])->assertOk();

    $reversal = PaymentReversal::withoutBrandScope()->sole();

    expect($reversal->kind)->toBe(ReversalKind::ExternalRefund)
        ->and($reversal->provider_reference)->toBe('re_test_1')
        ->and((string) $reversal->amount)->toBe('119.00')
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(CreditNote::withoutBrandScope()->where('order_id', $order->id)->count())->toBe(1);

    Event::assertDispatched(OrderRefunded::class, fn (OrderRefunded $e): bool => $e->whole === true);
});

it('ignores a charge refund that our own RefundOrder already explains', function (): void {
    [$order] = stripeWebhookTestSettled();
    $order->forceFill(['status' => OrderStatus::Refunded])->save();

    Event::fake([OrderRefunded::class]);

    stripeWebhookTestPostObject('charge.refunded', 'charge', [
        'id' => 'ch_test_1',
        'payment_intent' => 'pi_test_settled',
        'amount' => 11900,
        'amount_refunded' => 11900,
        'currency' => 'eur',
        'refunds' => ['object' => 'list', 'data' => [['id' => 're_test_1']]],
    ])->assertOk();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(0);

    Event::assertNotDispatched(OrderRefunded::class);
});

it('acknowledges a dispute for a payment intent it does not know', function (): void {
    stripeWebhookTestSettled();

    stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('lost', [
        'payment_intent' => 'pi_somebody_else',
        'charge' => 'ch_somebody_else',
    ]))->assertOk();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(0);
});

it('converges when Stripe replays the dispute notification', function (): void {
    [$order] = stripeWebhookTestSettled();

    Event::fake([PaymentReversed::class]);

    foreach (range(1, 3) as $attempt) {
        stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('lost'))
            ->assertOk();
    }

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertDispatchedTimes(PaymentReversed::class, 1);
});

it('reverses an order whose admission was already checked in', function (): void {
    // #166's second path. ADR-0037's guard belongs to the *voluntary*
    // refund; a chargeback already happened, and attendance does not undo
    // it. Attendance only stops the deadline sweep from cancelling the
    // order later (ADR-0057 point 4).
    [$order, $payment] = stripeWebhookTestSettled();

    CheckedInAdmission::create([
        'admission_id' => (string) Str::ulid(),
        'registration_id' => $order->settles_id,
        'first_checked_in_at' => now(),
    ]);

    Event::fake([PaymentReversed::class]);

    stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('lost'))
        ->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::PaymentReversed)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Reversed);

    Event::assertDispatched(PaymentReversed::class);
});

it('records a partial dashboard refund as a partial OrderRefunded', function (): void {
    [$order] = stripeWebhookTestSettled();
    Invoice::factory()->create(['order_id' => $order->id]);

    Event::fake([OrderRefunded::class]);

    stripeWebhookTestPostObject('charge.refunded', 'charge', [
        'id' => 'ch_test_1',
        'payment_intent' => 'pi_test_settled',
        'amount' => 11900,
        'amount_refunded' => 2000,
        'currency' => 'eur',
        'refunds' => ['object' => 'list', 'data' => [['id' => 're_test_partial']]],
    ])->assertOk();

    expect((string) PaymentReversal::withoutBrandScope()->sole()->amount)->toBe('20.00');

    // ADR-0057 point 5: Registration cancels only on a whole-order refund,
    // and the amount alone cannot say which this was.
    Event::assertDispatched(
        OrderRefunded::class,
        fn (OrderRefunded $e): bool => $e->amountRefunded === '20.00' && $e->whole === false,
    );
});

it('records money that arrives after the reservation has expired', function (): void {
    // #6: the checkout-grace sweep cancelled the order, then the payer's
    // delayed SEPA debit finally cleared. The money moved; a warning in a
    // log is not a record of it.
    [$order, $payment] = stripeWebhookTestPending();
    $order->forceFill(['status' => OrderStatus::Cancelled])->save();

    Event::fake([OrderPaid::class, PaymentUnapplied::class]);

    stripeWebhookTestPost('checkout.session.async_payment_succeeded', [
        'id' => 'cs_test_abc', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_1',
    ])->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(Payment::withoutBrandScope()->unapplied()->pluck('id')->all())->toBe([$payment->id]);

    Event::assertNotDispatched(OrderPaid::class);
    Event::assertDispatched(
        PaymentUnapplied::class,
        fn (PaymentUnapplied $e): bool => $e->orderId === $order->id && $e->amount === '119.00',
    );
});

it('dates the deadline from the day the dispute was lost, not the day it opened', function (): void {
    // Fix round 1, finding 1. A card dispute stays open 20-75 days; taking
    // the dispute's own `created` for the closing moment put `repay_due_on`
    // in the past, so the deadline sweep (ADR-0057 point 4) would cancel
    // the order on its first run and the payer would get no days at all.
    [$order] = stripeWebhookTestSettled();

    $opened = stripeWebhookTestDispute('needs_response', [
        'created' => Carbon::parse('2026-07-20 08:00:00')->getTimestamp(),
    ]);

    stripeWebhookTestPostObject('charge.dispute.created', 'dispute', $opened, '2026-07-20 08:00:00')
        ->assertOk();

    Event::fake([PaymentReversed::class]);

    stripeWebhookTestPostObject(
        'charge.dispute.closed',
        'dispute',
        stripeWebhookTestDispute('lost', ['created' => Carbon::parse('2026-07-20 08:00:00')->getTimestamp()]),
        '2026-09-07 09:00:00',
    )->assertOk();

    $reversal = PaymentReversal::withoutBrandScope()->sole();

    expect($reversal->occurred_at->toDateString())->toBe('2026-07-20')
        ->and($reversal->finalized_at?->toDateString())->toBe('2026-09-07')
        ->and($reversal->repay_due_on?->toDateString())->toBe('2026-09-14')
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReversed);

    Event::assertDispatched(
        PaymentReversed::class,
        fn (PaymentReversed $e): bool => $e->repayDueOn === '2026-09-14'
            && str_starts_with($e->reversedAt, '2026-09-07'),
    );
});

it('dates a dashboard refund from the refund, not from the charge', function (): void {
    [$order] = stripeWebhookTestSettled();

    stripeWebhookTestPostObject('charge.refunded', 'charge', [
        'id' => 'ch_test_1',
        'payment_intent' => 'pi_test_settled',
        'amount' => 11900,
        'amount_refunded' => 11900,
        'currency' => 'eur',
        // The charge is old; the refund happened today.
        'created' => Carbon::parse('2026-07-20 08:00:00')->getTimestamp(),
        'refunds' => ['object' => 'list', 'data' => [[
            'id' => 're_test_1',
            'created' => Carbon::parse('2026-09-07 09:00:00')->getTimestamp(),
        ]]],
    ], '2026-09-07 09:00:00')->assertOk();

    expect(PaymentReversal::withoutBrandScope()->sole()->occurred_at->toDateString())->toBe('2026-09-07')
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);
});

it('acknowledges a dispute in a currency it cannot read as minor units', function (): void {
    // Fix round 1, finding 3. MinorUnits refuses zero-decimal currencies
    // rather than mis-billing them; letting that throw out of the webhook
    // would answer 500 and make Stripe retry for days, against this
    // endpoint's own 200-on-anything-we-cannot-act-on contract.
    [$order, $payment] = stripeWebhookTestSettled();

    stripeWebhookTestPostObject('charge.dispute.created', 'dispute', stripeWebhookTestDispute('lost', [
        'currency' => 'jpy',
        'amount' => 11900,
    ]))->assertOk();

    expect(PaymentReversal::withoutBrandScope()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
});

it('keeps the local tunnel script subscribed to exactly the events this endpoint handles', function (): void {
    // `contrib/stripe-listen.sh` is bash and cannot read the PHP constant,
    // so this is what keeps its `--events` list from drifting away from
    // `StripeWebhookEvents::ALL` — and with it, from the list the operator
    // console tells an operator to subscribe a deployed endpoint to (#161).
    $script = (string) file_get_contents(base_path('contrib/stripe-listen.sh'));

    expect($script)->toContain('events="'.implode(',', StripeWebhookEvents::ALL).'"');
});
