<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners\Metrics;

use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

/**
 * `admission_hub_access_total` (D-HUB-01, ADR-0059): one increment per
 * participant the live hub opened for, and one per access that ended,
 * labelled with how. The outcome set is bounded — `admitted` plus
 * `HubAccessEndReason`'s four values, which travel on the event as the
 * enum's backing string — never an id. `ParticipantPresenceChanged` is
 * deliberately not counted: "on site" is a badge, not access, and the
 * door's scan tally is already readable from `check_ins`.
 *
 * `Metrics` resolved in the handlers, as every recorder here does
 * (`.ai/rules/listeners.md`).
 */
final class RecordPresenceMetrics
{
    public function handleAdmitted(ParticipantAdmitted $event): void
    {
        $this->hubAccess($event->brandId, 'admitted');
    }

    public function handleEnded(ParticipantHubAccessEnded $event): void
    {
        $this->hubAccess($event->brandId, $event->reason);
    }

    private function hubAccess(string $brandId, string $outcome): void
    {
        app(Metrics::class)->increment('admission_hub_access_total', [
            'brand' => BrandSlugs::of($brandId),
            'outcome' => $outcome,
        ]);
    }
}
