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
