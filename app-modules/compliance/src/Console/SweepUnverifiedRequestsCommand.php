<?php

declare(strict_types=1);

namespace Konventix\Compliance\Console;

use Illuminate\Console\Command;
use Konventix\Compliance\Models\DataSubjectRequestVerification;

/**
 * Delete unverified request addresses past their 24-hour expiry.
 *
 * An unverified address is not a record of anything that happened — it is
 * merely someone's claim to an email address, held pending proof they own it.
 * A deleted, never anonymized: the email goes; no history of it remains.
 */
final class SweepUnverifiedRequestsCommand extends Command
{
    protected $signature = 'compliance:sweep-unverified-requests';

    protected $description = 'Delete unverified data-subject-request addresses past their 24-hour expiry';

    public function handle(): int
    {
        // Deleted, never anonymized: an unverified address is not a record
        // of anything that happened. Nobody proved it was theirs.
        $deleted = DataSubjectRequestVerification::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $this->info("Deleted {$deleted} unverified request address(es).");

        return self::SUCCESS;
    }
}
