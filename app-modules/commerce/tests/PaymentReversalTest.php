<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\Order;
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
