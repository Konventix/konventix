<?php

declare(strict_types=1);

namespace Konventix\Commerce\Console;

use Illuminate\Console\Command;
use Konventix\Commerce\Actions\RaiseDunningLevel;

/**
 * The scheduled dunning sweep. Registered on the scheduler in
 * `CommerceServiceProvider`, which runs as its own container role — the
 * dev stack's `scheduler` service, mirroring production (ADR-0002).
 *
 * Daily rather than hourly: dunning levels are measured in days, so a more
 * frequent sweep would do the same work repeatedly to reach the same answer.
 * It is idempotent regardless — `InvoiceOverdue` fires once per invoice and a
 * level only ever moves up — so a double run is harmless.
 */
final class RunDunningSweep extends Command
{
    protected $signature = 'commerce:dunning {--brand= : Restrict the sweep to one brand}';

    protected $description = 'Flag overdue invoices and raise dunning levels (Commerce).';

    public function handle(RaiseDunningLevel $action): int
    {
        $result = $action->handle($this->option('brand'));

        $this->info(sprintf(
            'Dunning sweep complete: %d newly overdue, %d level(s) raised.',
            $result['overdue'],
            $result['raised'],
        ));

        return self::SUCCESS;
    }
}
