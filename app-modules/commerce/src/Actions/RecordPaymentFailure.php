<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Payment;

/**
 * The webhook-side failure transition (ADR-0039), shared by every PSP
 * adapter. The order stays pending — a failed attempt settles nothing and
 * a new checkout may follow. A payment that already succeeded is never
 * demoted: providers do not guarantee notification order, and a stale
 * failure arriving after the success would otherwise un-pay real money.
 *
 * Reads escape BrandScope explicitly (ADR-0010): this runs on the
 * stateless api surface, where no brand is resolved from the host.
 */
final class RecordPaymentFailure
{
    public function handle(Payment $payment, string $reasonCode, bool $expired = false): Payment
    {
        return DB::transaction(function () use ($payment, $reasonCode, $expired): Payment {
            $locked = Payment::withoutBrandScope()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== PaymentStatus::Pending) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $expired ? PaymentStatus::Expired : PaymentStatus::Failed,
                'failure_reason' => $reasonCode,
                'failed_at' => now(),
            ])->save();

            $this->stampFirstFailedAttempt($locked);

            event(new PaymentFailed(
                paymentId: $locked->id,
                orderId: $locked->order_id,
                brandId: $locked->brand_id,
                settlementMethod: $locked->provider,
                reasonCode: $reasonCode,
                failedAt: $locked->failed_at->toIso8601String(),
                settlementAccountId: $locked->settlement_account_id,
            ));

            return $locked->refresh();
        });
    }

    /**
     * The moment the release valves of #231 count from (operator decision
     * D-0.8.2-4, #249). Before the column they counted from the end of the
     * order's payment window, which for a direct registration order is its
     * `created_at` plus an hour — so an order placed weeks ago whose payer
     * only starts failing today reached both expiry sweeps with the valve
     * already expired, and the first answer a provider could not give
     * cancelled it with no grace at all.
     *
     * Stamped **once** and never moved: the decision names the *first*
     * failed attempt, so a payer who retries five times does not push the
     * valve out five times. `whereNull` in the statement rather than a read
     * and a write makes that idempotent in the database rather than in this
     * method — two failures committing at the same moment cannot both win.
     *
     * `withoutBrandScope()` (ADR-0010): this runs on the stateless api
     * surface and from console sweeps, where no brand is resolved from a
     * host.
     */
    private function stampFirstFailedAttempt(Payment $payment): void
    {
        Order::withoutBrandScope()
            ->whereKey($payment->order_id)
            ->whereNull('first_failed_attempt_at')
            ->update(['first_failed_attempt_at' => $payment->failed_at]);
    }
}
