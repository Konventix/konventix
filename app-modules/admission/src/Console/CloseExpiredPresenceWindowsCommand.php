<?php

declare(strict_types=1);

namespace Konventix\Admission\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Konventix\Admission\Actions\ProjectEventPresence;

/**
 * Closes once-per-day hub windows whose day has ended (D-HUB-01). Kept as
 * a state flip plus an event rather than date math in every consumer, so
 * the topbar pill and `/live` read one boolean.
 */
final class CloseExpiredPresenceWindowsCommand extends Command
{
    protected $signature = 'admission:close-presence-windows';

    protected $description = 'End hub access for participants whose event day has ended.';

    public function handle(ProjectEventPresence $presence): int
    {
        $ended = $presence->closeExpiredWindows(CarbonImmutable::now());

        $this->info("Closed {$ended} presence window(s).");

        return self::SUCCESS;
    }
}
