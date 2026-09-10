<?php

declare(strict_types=1);

namespace Konventix\Resources\Console;

use Illuminate\Console\Command;
use Konventix\Resources\Actions\ExpireResourceHolds;

/**
 * Scheduled every minute (`ResourcesServiceProvider::registerSchedule()`):
 * lapsed clocked holds become `expired` and free their slot. The work is
 * `Actions\ExpireResourceHolds`; this class is the console wrapper.
 */
final class ExpireResourceHoldsCommand extends Command
{
    protected $signature = 'resources:expire-holds';

    protected $description = 'Expire lapsed resource holds and free the intervals they occupied (Resources).';

    public function handle(ExpireResourceHolds $expireResourceHolds): int
    {
        $expired = $expireResourceHolds->handle();

        $this->components->info("Expired {$expired} lapsed hold(s).");

        return self::SUCCESS;
    }
}
