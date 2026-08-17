<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\DunningLevelRaised;
use Konventix\Commerce\Events\InvoiceOverdue;
use Konventix\Commerce\Models\Invoice;

/**
 * The dunning sweep: finds issued invoices past their due date whose order is
 * still unpaid, and escalates them.
 *
 * Two events, deliberately separate. `InvoiceOverdue` fires **once** per
 * invoice — the sweep runs daily and re-emitting would turn a single unpaid
 * invoice into a daily notification about the same unchanging fact.
 * `DunningLevelRaised` fires per escalation, which is genuinely new
 * information each time.
 *
 * Commerce decides *that* a level was reached; it does not decide what a
 * level means to a recipient. Messaging owns wording, channel and timing —
 * the same split D-CNT-08 draws for announcements. That is why no mail is
 * sent from here.
 *
 * Levels are days-overdue thresholds rather than a fixed calendar, so an
 * invoice that goes unpaid for months settles at the top level instead of
 * escalating forever.
 */
final class RaiseDunningLevel
{
    /**
     * Days overdue at which each level is reached. Deliberately conservative:
     * a first reminder a week after the due date reads as a courtesy, one the
     * next morning reads as an accusation.
     *
     * @var list<int>
     */
    public const array LEVEL_THRESHOLDS = [7, 21, 42];

    /**
     * @return array{overdue: int, raised: int}
     */
    public function handle(?string $brandId = null): array
    {
        $overdue = 0;
        $raised = 0;

        $query = Invoice::withoutBrandScope()
            ->whereDate('due_on', '<', now())
            ->whereHas('order', fn ($order) => $order->whereNotIn('status', [
                OrderStatus::Paid->value,
                OrderStatus::Cancelled->value,
                OrderStatus::Refunded->value,
            ]));

        if ($brandId !== null) {
            $query->where('brand_id', $brandId);
        }

        foreach ($query->cursor() as $invoice) {
            $days = (int) $invoice->due_on->startOfDay()->diffInDays(now()->startOfDay());

            DB::transaction(function () use ($invoice, $days, &$overdue, &$raised): void {
                if ($invoice->overdue_at === null) {
                    $invoice->forceFill(['overdue_at' => now()])->save();
                    $overdue++;

                    event(new InvoiceOverdue(
                        invoiceId: $invoice->id,
                        invoiceNumber: $invoice->invoice_number,
                        orderId: $invoice->order_id,
                        brandId: $invoice->brand_id,
                        totalGross: (string) $invoice->total_gross,
                        currency: $invoice->currency,
                        dueOn: $invoice->due_on->toDateString(),
                        daysOverdue: $days,
                        detectedAt: now()->toIso8601String(),
                    ));
                }

                $target = $this->levelFor($days);

                if ($target > $invoice->dunning_level) {
                    $invoice->forceFill([
                        'dunning_level' => $target,
                        'dunning_raised_at' => now(),
                    ])->save();
                    $raised++;

                    event(new DunningLevelRaised(
                        invoiceId: $invoice->id,
                        invoiceNumber: $invoice->invoice_number,
                        orderId: $invoice->order_id,
                        brandId: $invoice->brand_id,
                        level: $target,
                        daysOverdue: $days,
                        raisedAt: now()->toIso8601String(),
                        billingName: $invoice->billing_party->displayName(),
                        billingEmail: $invoice->billing_party->email,
                        totalGross: $invoice->total_gross,
                        currency: $invoice->currency,
                        dueOn: $invoice->due_on->toDateString(),
                    ));
                }
            });
        }

        return ['overdue' => $overdue, 'raised' => $raised];
    }

    /**
     * The highest threshold this invoice has passed. Levels never decrease —
     * an invoice cannot become less overdue.
     */
    private function levelFor(int $daysOverdue): int
    {
        $level = 0;

        foreach (self::LEVEL_THRESHOLDS as $index => $threshold) {
            if ($daysOverdue >= $threshold) {
                $level = $index + 1;
            }
        }

        return $level;
    }
}
