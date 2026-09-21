<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners\Metrics;

use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;
use Konventix\Registration\Events\RegistrationEntryConditionAsserted;
use Konventix\Registration\Events\RegistrationEntryConditionDecided;

/**
 * The two entry-condition counters (ADR-0059, spec §10), one listener for
 * both events — the same shape as Commerce's `RecordOrderMetrics`.
 *
 * Labels are the events' own bounded enum values plus the brand slug. The
 * condition's `key` is carried by both events and deliberately never
 * recorded: an operator-defined key is unbounded cardinality (T-11).
 *
 * `Metrics` swallows a storage failure and reports it, so no try/catch here:
 * counting a declaration must never abort the submission that made it.
 */
final class RecordEntryConditionMetrics
{
    public function handle(RegistrationEntryConditionAsserted|RegistrationEntryConditionDecided $event): void
    {
        $metrics = app(Metrics::class);
        $brand = BrandSlugs::of($event->brandId);

        if ($event instanceof RegistrationEntryConditionAsserted) {
            $metrics->increment('entry_condition_assertions_total', [
                'brand' => $brand,
                'verification' => $event->verification,
            ]);

            return;
        }

        $metrics->increment('entry_condition_decisions_total', [
            'brand' => $brand,
            'state' => $event->state,
            'source' => $event->decidedVia,
        ]);
    }
}
