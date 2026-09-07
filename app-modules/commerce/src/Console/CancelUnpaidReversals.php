<?php

declare(strict_types=1);

namespace Konventix\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Actions\CancelOrderForUnpaidReversal;
use Konventix\Commerce\Actions\Concerns\GuardsCheckedInAdmissions;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Enums\ReversalKind;
use Konventix\Commerce\Enums\ReversalStatus;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\PaymentReversal;
use Throwable;

/**
 * The deadline sweep of ADR-0057 point 4, shaped like `RunDunningSweep` and
 * scheduled beside it: every `payment_reversed` order whose newest final
 * `return` or `dispute` has an expired `repay_due_on` loses the order.
 *
 * **Driven from the orders, not from the reversal rows.** An order can be
 * reversed, repaid and reversed again: the first reversal's deadline is
 * long past, and a sweep that iterated every historical final row would
 * find it still matching and cancel the order the morning after the second
 * reversal — days before the deadline that payer was actually given. Only
 * the newest final reversal decides, the same rule the operator console's
 * `repaymentDeadline()` reads. It also stops the sweep re-reading every
 * reversal the deployment has ever recorded, every day.
 *
 * Daily rather than hourly, for the reason the dunning sweep gives: the
 * deadline is a date, so a more frequent run would do the same work
 * repeatedly to reach the same answer. Idempotent regardless —
 * `CancelOrderForUnpaidReversal` refuses an order that is no longer
 * `payment_reversed`, so a second run in the same day finds nothing.
 *
 * **An order whose admission was already used is never cancelled.** Someone
 * attended, and what they owe is a receivable, not a cancellation: the
 * invoice's `overdue_at` is set to the deadline it missed and the ordinary
 * dunning ladder (`RunDunningSweep`) takes it from there. `overdue_at` is
 * written only where it is still null — `RaiseDunningLevel` treats it as
 * "when this first went overdue" and emits `InvoiceOverdue` once off it.
 *
 * `external_refund` is excluded: it is not a receivable at all. The
 * operator refunded in the provider's dashboard, `RecordPaymentReversal`
 * already put the order into `refunded`, and there is nothing owed to
 * cancel for. A `withdrawn` dispute is excluded for the mirror reason —
 * the money stayed.
 *
 * Every read escapes BrandScope explicitly (ADR-0010): a console command
 * resolves no brand from a host. One order's failure never aborts the
 * sweep — and an order that left `payment_reversed` between this loop's
 * read and the Action's lock is a payer paying, logged at info level rather
 * than reported as a failure.
 */
final class CancelUnpaidReversals extends Command
{
    use GuardsCheckedInAdmissions;

    protected $signature = 'commerce:cancel-unpaid-reversals {--brand= : Restrict the sweep to one brand}';

    protected $description = 'Cancel orders whose reversed payment went unpaid past its deadline (Commerce, ADR-0057).';

    public function handle(CancelOrderForUnpaidReversal $cancel): int
    {
        $cancelled = 0;
        $heldForDunning = 0;

        $query = Order::withoutBrandScope()
            ->where('status', OrderStatus::PaymentReversed)
            ->orderBy('id');

        $brandId = $this->option('brand');

        if (is_string($brandId) && $brandId !== '') {
            $query->where('brand_id', $brandId);
        }

        foreach ($query->cursor() as $order) {
            $reversal = $this->deadlineReversal($order);

            // No final reversal with a deadline yet (an open dispute holds
            // none), or the deadline has not passed: nothing is owed today.
            if ($reversal === null || $reversal->repay_due_on === null
                || ! $reversal->repay_due_on->startOfDay()->isBefore(now()->startOfDay())) {
                continue;
            }

            try {
                if ($this->checkedInRegistrationId($order) !== null) {
                    $this->handToDunning($order, $reversal);
                    $heldForDunning++;

                    continue;
                }

                $cancel->handle($order);
                $cancelled++;
            } catch (Throwable $e) {
                // A payer who put the money back between the read above and
                // `CancelOrderForUnpaidReversal`'s own locked guard is a
                // benign race, not a failure: the guard refuses precisely
                // because the order is no longer `payment_reversed`, which
                // is the right answer. Re-read under no illusion that the
                // in-memory row is current, and say so at info level — an
                // error-level "sweep failed" would page someone about money
                // arriving.
                $current = Order::withoutBrandScope()->find($order->id);

                if ($current === null || $current->status !== OrderStatus::PaymentReversed) {
                    Log::info('Unpaid-reversal sweep skipped an order that left payment_reversed under the lock.', [
                        'order_id' => $order->id,
                        'brand_id' => $order->brand_id,
                        'reversal_id' => $reversal->id,
                        'status' => $current?->status->value,
                    ]);

                    continue;
                }

                Log::error(sprintf(
                    'Unpaid-reversal sweep failed for order [%s]: %s',
                    $order->id,
                    $e->getMessage(),
                ), ['exception' => $e, 'reversal_id' => $reversal->id, 'brand_id' => $reversal->brand_id]);
            }
        }

        $this->components->info(sprintf(
            'Unpaid-reversal sweep complete: %d order(s) cancelled, %d left as a receivable after check-in.',
            $cancelled,
            $heldForDunning,
        ));

        return self::SUCCESS;
    }

    /**
     * The reversal whose deadline decides — the newest final `return` or
     * `dispute` the order has. An `external_refund` is excluded because it
     * is not a receivable at all (the order is already `refunded`), and a
     * `withdrawn` dispute because the money stayed.
     */
    private function deadlineReversal(Order $order): ?PaymentReversal
    {
        return PaymentReversal::withoutBrandScope()
            ->where('order_id', $order->id)
            ->where('status', ReversalStatus::Final)
            ->whereIn('kind', [ReversalKind::Return->value, ReversalKind::Dispute->value])
            ->whereNotNull('repay_due_on')
            ->orderByDesc('occurred_at')
            ->first();
    }

    /**
     * The invoice enters the dunning ladder as of the deadline it missed,
     * not as of today: the days-overdue thresholds
     * (`RaiseDunningLevel::LEVEL_THRESHOLDS`) should count from when the
     * money was due back.
     */
    private function handToDunning(Order $order, PaymentReversal $reversal): void
    {
        $invoice = Invoice::withoutBrandScope()
            ->where('brand_id', $order->brand_id)
            ->where('order_id', $order->id)
            ->whereNull('overdue_at')
            ->latest('issued_on')
            ->first();

        if ($invoice === null || $reversal->repay_due_on === null) {
            return;
        }

        $invoice->forceFill(['overdue_at' => $reversal->repay_due_on])->save();

        Log::info('Unpaid reversal left as a receivable: the admission was already used (ADR-0057 point 4).', [
            'order_id' => $order->id,
            'brand_id' => $order->brand_id,
            'invoice_id' => $invoice->id,
            'repay_due_on' => $reversal->repay_due_on->toDateString(),
        ]);
    }
}
