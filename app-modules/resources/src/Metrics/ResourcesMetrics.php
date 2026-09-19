<?php

declare(strict_types=1);

namespace Konventix\Resources\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Resources' entries in the metric catalogue (ADR-0059, design §9.6).
 *
 * Counters are incremented by the listeners in src/Listeners/Metrics on
 * this module's own events; gauges are answered by ResourcesGauges at
 * scrape time. Labels are bounded sets — the brand slug, an outcome and,
 * for the unplaced-claims gauge, the event slug — never a registration, a
 * user or a place (.ai/rules/observability.md).
 *
 * `refused` is a pick that lost the race for a place: the exclusion
 * constraint or a deadlock refused the hold and the registrant was told to
 * pick again. It is the one outcome that is not a booking state, which is
 * why it needs its own event (`ResourceBookingRefused`) rather than a row.
 *
 * `resource_seat_claims_unplaced` carries `event` as well as `brand`
 * (2026-09-10 batch amendment, ahead of design §12b's quota decision): a
 * claim that is still unplaced once the picker has opened means someone
 * paid and cannot sit down, and an operator needs to know which event is
 * short, not only which brand — a brand-only count cannot answer that.
 */
final class ResourcesMetrics
{
    public const array BOOKING_OUTCOMES = ['held', 'confirmed', 'released', 'expired', 'refused'];

    public const array PLAN_SAVE_OUTCOMES = ['saved', 'conflict', 'invalid', 'stale'];

    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition('resource_bookings_total', MetricKind::Counter, 'Bookings by outcome: held, confirmed, released, expired, refused.', ['brand', 'outcome']),
            // #292 §9: the per-ticket half of the same story — a booking that
            // names an admission, confirmed (placed) or released (given up,
            // moved, revoked). Refusals stay on resource_bookings_total.
            new MetricDefinition('resource_admission_seats_total', MetricKind::Counter, 'Seats bound to a ticket, by outcome: placed, released.', ['brand', 'outcome']),
            new MetricDefinition('resource_plan_saves_total', MetricKind::Counter, 'Plan saves by outcome: saved, conflict, invalid, stale.', ['brand', 'outcome']),
            new MetricDefinition('resource_seat_claims_unplaced', MetricKind::Gauge, 'Seat claims whose registrant has not picked a place yet, by event.', ['brand', 'event']),
            new MetricDefinition('resource_hold_sweep_last_run_timestamp_seconds', MetricKind::Gauge, 'When resources:expire-holds last finished a run.'),
        ];
    }
}
