<?php

declare(strict_types=1);

namespace Konventix\Compliance\Listeners;

use Konventix\Compliance\Events\DataExportRequested;
use Konventix\Compliance\Events\UserDeletionRequested;
use Konventix\Compliance\Jobs\AssembleSubjectExport;
use Konventix\Compliance\Jobs\RunSubjectDeletion;

/**
 * Turns the two fan-out events into the queued work that carries them out.
 *
 * Compliance listens to its *own* events here rather than calling the jobs
 * from `OpenSubjectRequest`, so that re-dispatch (`RedispatchSubjectRequest`,
 * which only re-emits) runs exactly the same path as the original request. A
 * retry that took a different route would be a second implementation of the
 * fan-out, and the one that gets less use is the one that rots.
 *
 * `afterCommit()` is load-bearing: `OpenSubjectRequest` emits inside its
 * transaction, and a worker that picked the job up before the commit would
 * find no request row.
 *
 * Registered explicitly in ComplianceServiceProvider — modular event
 * discovery is off and must stay off (.ai/rules/listeners.md).
 */
final class DispatchSubjectRequestFanOut
{
    public function handleExport(DataExportRequested $event): void
    {
        AssembleSubjectExport::dispatch($event->requestId)->afterCommit();
    }

    public function handleDeletion(UserDeletionRequested $event): void
    {
        RunSubjectDeletion::dispatch($event->requestId)->afterCommit();
    }
}
