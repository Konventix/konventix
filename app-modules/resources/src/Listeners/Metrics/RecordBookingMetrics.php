<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners\Metrics;

use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;
use Konventix\Resources\Events\ResourceBookingConfirmed;
use Konventix\Resources\Events\ResourceBookingHeld;
use Konventix\Resources\Events\ResourceBookingRefused;
use Konventix\Resources\Events\ResourceBookingReleased;

/**
 * One listener for the five booking outcomes (ADR-0059, design §9.6).
 *
 * `expired` is a release with reason `expired` — the hold sweep releases
 * through `ReleaseResourceBooking` like everything else, so the counter
 * reads the reason instead of needing a fourth event. Every other release
 * reason (`registration_cancelled`, `repicked`, `plan_conflict`, …) is
 * `released`; the reason itself is not a label, it would multiply series
 * for a question nobody asks a dashboard.
 *
 * Scoped bindings are resolved inside handle() (.ai/rules/listeners.md).
 */
final class RecordBookingMetrics
{
    public function handle(ResourceBookingHeld|ResourceBookingConfirmed|ResourceBookingReleased|ResourceBookingRefused $event): void
    {
        $outcome = match (true) {
            $event instanceof ResourceBookingHeld => 'held',
            $event instanceof ResourceBookingConfirmed => 'confirmed',
            $event instanceof ResourceBookingRefused => 'refused',
            $event->reason === 'expired' => 'expired',
            default => 'released',
        };

        app(Metrics::class)->increment('resource_bookings_total', [
            'brand' => BrandSlugs::of($event->brandId),
            'outcome' => $outcome,
        ]);

        // The per-ticket counter (#292 §9): only bookings bound to an
        // admission, only the two outcomes a ticket's seat has.
        if (($event instanceof ResourceBookingConfirmed || $event instanceof ResourceBookingReleased) && $event->admissionId !== null) {
            app(Metrics::class)->increment('resource_admission_seats_total', [
                'brand' => BrandSlugs::of($event->brandId),
                'outcome' => $event instanceof ResourceBookingConfirmed ? 'placed' : 'released',
            ]);
        }
    }
}
