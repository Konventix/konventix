<?php

declare(strict_types=1);

namespace Konventix\Registration\Console;

use Illuminate\Console\Command;
use Konventix\Registration\Actions\ExpireWaitlistOffers;

/**
 * Scheduled every fifteen minutes (RegistrationServiceProvider::boot):
 * lapsed offers release their held quota units, which re-triggers
 * promotion of the next entry (D-REG-12).
 */
class ExpireWaitlistOffersCommand extends Command
{
    protected $signature = 'registration:expire-waitlist-offers';

    protected $description = 'Expire lapsed waitlist offers and release their held quota units';

    public function handle(ExpireWaitlistOffers $expireWaitlistOffers): int
    {
        $expired = $expireWaitlistOffers->handle();

        $this->info("Expired {$expired} lapsed waitlist offer(s).");

        return self::SUCCESS;
    }
}
