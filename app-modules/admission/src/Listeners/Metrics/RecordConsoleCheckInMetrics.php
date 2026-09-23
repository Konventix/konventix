<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners\Metrics;

use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Events\ConsoleCheckInRecorded;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

/**
 * `admission_console_check_ins_total` (#329, ADR-0059): one increment per
 * row the console wrote. The outcome set is bounded — `in` and `out` for an
 * accepted row, `refused` for one the shared core rejected — never an id or
 * a reason sentence (`.ai/rules/observability.md`). A console attempt refused
 * before anything was written raises no `ConsoleCheckInRecorded` and is not
 * counted: nothing happened at the door, the operator was told why.
 *
 * `Metrics` resolved in the handler, as every recorder here does
 * (`.ai/rules/listeners.md`).
 */
final class RecordConsoleCheckInMetrics
{
    public function handle(ConsoleCheckInRecorded $event): void
    {
        app(Metrics::class)->increment('admission_console_check_ins_total', [
            'brand' => BrandSlugs::of($event->brandId),
            'outcome' => $event->result === CheckInResult::Rejected->value ? 'refused' : $event->direction,
        ]);
    }
}
