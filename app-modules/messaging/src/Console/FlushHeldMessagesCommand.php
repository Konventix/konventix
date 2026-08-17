<?php

declare(strict_types=1);

namespace Konventix\Messaging\Console;

use Illuminate\Console\Command;
use Konventix\Messaging\Actions\FlushHeldMessages;

/**
 * The scheduled digest sweep (ADR-0041 point 5). Registered on the
 * scheduler in `MessagingServiceProvider` — module wiring never goes into
 * a central provider (ADR-0003).
 *
 * Every minute: hold windows are minute-granular (a 30-minute digest
 * window, quiet hours ending 08:00), and a sweep that finds nothing due is
 * one indexed query on (status, hold_until). Idempotent under overlap —
 * due rows are claimed under row locks.
 */
final class FlushHeldMessagesCommand extends Command
{
    protected $signature = 'messaging:flush-held';

    protected $description = 'Send due held messages, coalescing several per recipient into a digest (Messaging).';

    public function handle(FlushHeldMessages $action): int
    {
        $result = $action->handle();

        $this->info(sprintf(
            'Held-message flush complete: %d message(s) dispatched, of which %d digest(s).',
            $result['flushed'],
            $result['digests'],
        ));

        return self::SUCCESS;
    }
}
