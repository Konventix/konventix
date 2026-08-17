<?php

declare(strict_types=1);

namespace Konventix\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Models\Invoice;

/**
 * Reports which archived invoice documents have passed their statutory
 * retention window (ADR-0043 point 9, ADR-0031).
 *
 * It reports. It deletes nothing, and there is deliberately no command that
 * does: §147 AO runs ten years from the **end of the calendar year** in which
 * the invoice was issued, an early deletion is unrecoverable and a
 * bookkeeping offence, and Konventix has no invoice older than 2026 — so the
 * deletion semantics get their own decision when an operator is actually near
 * the limit, not a job written years ahead of its first real case.
 *
 * Scheduled daily and logged at INFO. Not an application-boot check: under
 * Octane "boot" happens per worker, which would make it noise rather than a
 * control.
 */
final class ReportInvoiceRetention extends Command
{
    /**
     * §147 AO's ten years. A constant rather than a config key: no module in
     * this codebase publishes config yet, and a retention period that varies
     * per deployment would need a decision, not a setting.
     */
    private const int RETENTION_YEARS = 10;

    protected $signature = 'commerce:invoice-retention
        {--years= : Override the retention period in years (default 10, §147 AO)}';

    protected $description = 'Report archived invoices past their statutory retention window — never deletes (Commerce).';

    public function handle(): int
    {
        $years = (int) ($this->option('years') ?? self::RETENTION_YEARS);

        // End of the calendar year of issue, plus the retention period: an
        // invoice issued any time in 2026 is retained until 2036-12-31.
        $cutoff = now()->startOfYear()->subYears($years);

        $expired = Invoice::withoutBrandScope()
            ->whereNotNull('document_path')
            ->where('issued_on', '<', $cutoff)
            ->count();

        $archived = Invoice::withoutBrandScope()->whereNotNull('document_path')->count();

        $summary = sprintf(
            'Invoice retention (%d years, §147 AO): %d archived document(s), %d past retention (issued before %s).',
            $years,
            $archived,
            $expired,
            $cutoff->format('Y-m-d'),
        );

        $this->components->info($summary);

        if ($expired > 0) {
            $this->components->warn(
                'Nothing is deleted automatically. Deleting archived invoices is a deliberate, '
                .'operator-run decision — see ADR-0031 and the roadmap entry for the retention '
                .'lifecycle before removing anything.'
            );
        }

        Log::info($summary, ['expired' => $expired, 'archived' => $archived, 'retention_years' => $years]);

        return self::SUCCESS;
    }
}
