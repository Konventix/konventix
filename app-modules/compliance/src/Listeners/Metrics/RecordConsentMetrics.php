<?php

declare(strict_types=1);

namespace Konventix\Compliance\Listeners\Metrics;

use Konventix\Compliance\Events\ConsentGranted;
use Konventix\Compliance\Events\ConsentPromptRaised;
use Konventix\Compliance\Events\ConsentWithdrawn;
use Konventix\Compliance\Metrics\ComplianceMetrics;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

/**
 * The three consent counters (ADR-0059, ADR-0066 spec §10), one listener for
 * the three events — the shape Commerce's `RecordOrderMetrics` established.
 *
 * Prompts and grants share one bounded `surface` set on purpose: the
 * dashboard derives the refusal rate by dividing one by the other
 * (D-CPL-17), and `KonventixConsentGateBlocking` fires on prompts without
 * grants. Two sets that did not line up would silence that alert.
 *
 * A withdrawal whose event carries no brand is not counted rather than
 * counted as `unknown`: `ConsentWithdrawn` predates the brand field, and a
 * row invented for a withdrawal recorded from a console command would put a
 * permanent `unknown` series on the panel.
 *
 * `Metrics` swallows a storage failure and reports it, so no try/catch here.
 */
final class RecordConsentMetrics
{
    public function handle(ConsentPromptRaised|ConsentGranted|ConsentWithdrawn $event): void
    {
        $metrics = app(Metrics::class);

        if ($event instanceof ConsentPromptRaised) {
            $metrics->increment('consent_prompts_total', [
                'brand' => BrandSlugs::of($event->brandId),
                'surface' => $event->surface,
                'reason' => $event->reason,
            ]);

            return;
        }

        if ($event instanceof ConsentGranted) {
            $metrics->increment('consent_grants_total', [
                'brand' => BrandSlugs::of($event->brandId),
                'surface' => ComplianceMetrics::surfaceForCaptureContext($event->captureContext),
            ]);

            return;
        }

        if ($event->brandId === null) {
            return;
        }

        $metrics->increment('consent_withdrawals_total', ['brand' => BrandSlugs::of($event->brandId)]);
    }
}
