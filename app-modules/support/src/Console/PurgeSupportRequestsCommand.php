<?php

declare(strict_types=1);

namespace Konventix\Support\Console;

use Illuminate\Console\Command;
use Konventix\Support\Actions\PurgeSupportRequests;

/**
 * The daily retention sweep (design §10.7, D-SUP-11). Registered on the
 * scheduler in `SupportServiceProvider` — module wiring never goes into a
 * central kernel (ADR-0003).
 *
 * The window comes from `config/support.php`, not from Compliance's
 * retention-rules UI: wiring the two is an open question in `SPEC.md` and
 * deliberately not answered here.
 *
 * `--days` is for the one case config cannot serve — an operator proving
 * what a shorter window would take before changing it.
 */
final class PurgeSupportRequestsCommand extends Command
{
    protected $signature = 'support:purge-requests {--days= : Override the retention window from config/support.php}';

    protected $description = 'Delete closed support requests past the retention window, with their threads (Support).';

    public function handle(PurgeSupportRequests $action): int
    {
        $option = $this->option('days');
        $days = $option === null ? null : (int) $option;

        $deleted = $action->handle($days);

        $this->info(sprintf(
            'Support retention sweep complete: %d closed request(s) deleted after %d day(s).',
            $deleted,
            $days ?? (int) config('support.retention.days', 180),
        ));

        return self::SUCCESS;
    }
}
