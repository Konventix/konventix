<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How long a payer has to put back money that was reversed (ADR-0057
 * point 4): the deadline `payment_reversals.repay_due_on` carries, after
 * which the `commerce:cancel-unpaid-reversals` sweep cancels the order.
 *
 * Seven days for every brand today. The ADR makes the window a Commerce
 * setting beside the waiting-room settings — one seam, so the per-brand
 * setting replaces the body of `daysFor()` and nothing else moves.
 *
 * Most SEPA returns are insufficient funds or a wrong mandate, fixed the
 * day the payer is told; a window rather than an immediate cancellation is
 * why a bank error does not hand a confirmed attendee's seat to the
 * waitlist a week early.
 */
final class RepaymentWindow
{
    /**
     * The default the ADR names. Kept on the class rather than in
     * `config/commerce.php`: the window is per brand, not per deployment,
     * so the setting it becomes belongs in the database, and a config key
     * would be a second place to look the day it lands.
     */
    public const int DEFAULT_DAYS = 7;

    /**
     * `$brandId` is taken today only so the per-brand setting Task 6 adds
     * changes this method's body and no caller.
     */
    public function daysFor(string $brandId): int
    {
        return self::DEFAULT_DAYS;
    }

    /**
     * The deadline is a date, not a moment: the sweep runs daily and
     * compares days, so the clock time a chargeback happened to arrive at
     * must not decide whether an order survives one more run.
     */
    public function dueOnFrom(CarbonInterface $reversedAt, string $brandId): CarbonImmutable
    {
        return CarbonImmutable::instance($reversedAt)
            ->startOfDay()
            ->addDays($this->daysFor($brandId));
    }
}
