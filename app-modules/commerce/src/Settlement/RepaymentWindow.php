<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Konventix\Commerce\Models\CommerceBrandSettings;

/**
 * How long a payer has to put back money that was reversed (ADR-0057
 * point 4): the deadline `payment_reversals.repay_due_on` carries, after
 * which the `commerce:cancel-unpaid-reversals` sweep cancels the order.
 *
 * Seven days unless the brand configured otherwise
 * (`CommerceBrandSettings`, the console form beside its settlement
 * accounts). This class is the single seam every caller reads through.
 *
 * Most SEPA returns are insufficient funds or a wrong mandate, fixed the
 * day the payer is told; a window rather than an immediate cancellation is
 * why a bank error does not hand a confirmed attendee's seat to the
 * waitlist a week early.
 */
final class RepaymentWindow
{
    /**
     * The default the ADR names, and the value a brand with no settings row
     * gets. Kept on the class rather than in `config/commerce.php`: the
     * window is per brand, not per deployment.
     */
    public const int DEFAULT_DAYS = 7;

    /**
     * The brand's own window where it configured one (ADR-0057 point 4),
     * seven days otherwise. Read at call time rather than injected: this
     * class is resolved once per Action and a settings row an operator
     * changes must take effect on the next reversal, not on the next
     * worker restart.
     */
    public function daysFor(string $brandId): int
    {
        return CommerceBrandSettings::forBrand($brandId)->reversal_repayment_days;
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
