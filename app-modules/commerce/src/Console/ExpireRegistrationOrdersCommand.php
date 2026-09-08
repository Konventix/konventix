<?php

declare(strict_types=1);

namespace Konventix\Commerce\Console;

use Illuminate\Console\Command;
use Konventix\Commerce\Actions\ExpireRegistrationOrders;

/**
 * Scheduled every fifteen minutes beside `commerce:expire-carts`
 * (`CommerceServiceProvider::registerSchedule()`): a direct registration
 * order that was never paid inside the carts' own payment window is
 * cancelled, so Registration releases the quota it was holding (#131).
 *
 * **Which orders it can touch**, so nobody has to read the Action to find
 * out (fix round 1): a `pending` order with `settles_type = registration`,
 * older than `Cart::CHECKOUT_GRACE_MINUTES`, with no invoice, **on a brand
 * that offers at least one hosted PSP checkout**. The last clause is the
 * one that keeps this honest — a brand settling by bank transfer tells its
 * payer the transfer takes days, and a sixty-minute deadline only makes
 * sense against a hosted session that has expired. `--brand` narrows a run
 * to one brand, for an operator investigating a single event.
 */
final class ExpireRegistrationOrdersCommand extends Command
{
    protected $signature = 'commerce:expire-registration-orders {--brand= : Restrict the sweep to one brand}';

    protected $description = 'Cancel direct registration orders never paid inside the payment window (Commerce, #131).';

    public function handle(ExpireRegistrationOrders $expire): int
    {
        $brandId = $this->option('brand');

        $cancelled = $expire->handle(is_string($brandId) && $brandId !== '' ? $brandId : null);

        $this->components->info("Cancelled {$cancelled} unpaid registration order(s).");

        return self::SUCCESS;
    }
}
