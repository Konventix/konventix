<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\DunningLevelRaised;
use Konventix\Commerce\Events\InvoiceOverdue;
use Konventix\Commerce\Models\Invoice;

/**
 * The dunning sweep: finds issued invoices past their due date whose order is
 * still unpaid, and escalates them.
 *
 * **Two arms, two clocks.** The general arm is every overdue invoice whose
 * order is neither paid, cancelled, refunded nor reversed, counted from
 * `due_on`. A `payment_reversed` order is deliberately not in it (ADR-0057
 * point 4): the payer holds a mail saying the order stands until
 * `repay_due_on`, and a PSP-paid invoice carries `due_on = issued_on`
 * (`IssueInvoice`, terms 0) — so a chargeback six weeks after the sale would
 * otherwise be "42 days overdue" on the very first sweep and mail a Mahnung
 * the same morning the reversal mail promised a deadline. The second arm
 * picks the reversed order up only once `CancelUnpaidReversals::handToDunning()`
 * has written `overdue_at` — the deadline that was actually missed — and
 * counts the ladder from there.
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

        foreach ($this->openReceivables($brandId)->cursor() as $invoice) {
            $this->escalate($invoice, $invoice->due_on, $overdue, $raised);
        }

        foreach ($this->handedOverReversals($brandId)->cursor() as $invoice) {
            // Never null: the arm's own `whereNotNull` is what defines it.
            $this->escalate($invoice, $invoice->overdue_at ?? $invoice->due_on, $overdue, $raised);
        }

        return ['overdue' => $overdue, 'raised' => $raised];
    }

    /**
     * The ordinary ladder: an overdue invoice whose order still owes the
     * money and was never reversed.
     *
     * @return Builder<Invoice>
     */
    private function openReceivables(?string $brandId): Builder
    {
        $query = Invoice::withoutBrandScope()
            ->whereDate('due_on', '<', now())
            ->whereHas('order', fn ($order) => $order->whereNotIn('status', [
                OrderStatus::Paid->value,
                OrderStatus::Cancelled->value,
                OrderStatus::Refunded->value,
                OrderStatus::PaymentReversed->value,
            ]));

        return $brandId === null ? $query : $query->where('brand_id', $brandId);
    }

    /**
     * The reversed orders the deadline sweep handed over (ADR-0057 point 4):
     * someone attended, so the money is a receivable rather than a
     * cancellation. `overdue_at` is the sweep's own hand-off marker and the
     * date the ladder counts from.
     *
     * @return Builder<Invoice>
     */
    private function handedOverReversals(?string $brandId): Builder
    {
        $query = Invoice::withoutBrandScope()
            ->whereNotNull('overdue_at')
            ->whereHas('order', fn ($order) => $order->where('status', OrderStatus::PaymentReversed->value));

        return $brandId === null ? $query : $query->where('brand_id', $brandId);
    }

    private function escalate(Invoice $invoice, CarbonInterface $countFrom, int &$overdue, int &$raised): void
    {
        $days = (int) $countFrom->copy()->startOfDay()->diffInDays(now()->startOfDay());

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
                    locale: $invoice->locale,
                ));
            }
        });
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
