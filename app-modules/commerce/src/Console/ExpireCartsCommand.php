<?php

declare(strict_types=1);

namespace Konventix\Commerce\Console;

use Illuminate\Console\Command;
use Konventix\Commerce\Actions\ExpireCarts;

/**
 * Scheduled every fifteen minutes (`CommerceServiceProvider::registerSchedule()`):
 * carts past their twenty-minute reservation window release the stock they
 * held and, for a registration line, tell Registration to cancel what it
 * was holding (ADR-0047, Task 9).
 */
final class ExpireCartsCommand extends Command
{
    protected $signature = 'commerce:expire-carts';

    protected $description = 'Expire open carts past their reservation window and release the stock they held (Commerce).';

    public function handle(ExpireCarts $expireCarts): int
    {
        $expired = $expireCarts->handle();

        $this->components->info("Expired {$expired} cart(s).");

        return self::SUCCESS;
    }
}
