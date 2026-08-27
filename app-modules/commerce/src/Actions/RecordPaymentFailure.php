<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\PaymentStatus;
use Konventix\Commerce\Events\PaymentFailed;
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
}
