<?php

declare(strict_types=1);

namespace Konventix\Competition\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Competition's entries in the metric catalogue (ADR-0059, design §13).
 *
 * - `competition_entrants_total{brand,state}` counts entrants moving into a
 *   state: `registered` or `incomplete` on entry, `checked_in`, and
 *   `withdrawn` or `disqualified` when one leaves. It is recorded by
 *   `Listeners\Metrics\RecordCompetitionMetrics` on this module's own
 *   `EntrantRegistered`, `EntrantCheckedIn` and `EntrantWithdrawn`. An
 *   incomplete roster that is filled up at signup close emits no event and
 *   is not counted a second time.
 * - `competition_matches_total{brand,state}` counts `scheduled`, one per
 *   `MatchScheduled` (a reschedule counts again, because the event goes out
 *   again), and `finished`, one per `MatchFinished`, which fires exactly
 *   once per match (D-CMP-12).
 * - `competition_disputes_open{brand}` is a gauge: disputed matches the desk
 *   has not decided, in `Running` competitions only. It is the one control
 *   that still works when nobody looks at the desk, and
 *   `KonventixCompetitionDisputeWaiting` fires when it has stayed above zero
 *   for 30 minutes.
 * - `competition_score_entries_unverified{brand}` is a gauge: leaderboard
 *   entries nobody has verified or rejected yet (§8.3), in competitions
 *   that are not over.
 *
 * `brand` is the slug. `state` is a value of `EntrantState` or `MatchState`.
 * Both are bounded sets, and no competition, match or entrant id is ever a
 * label (`.ai/rules/observability.md`).
 */
final class CompetitionMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition(
                'competition_entrants_total',
                MetricKind::Counter,
                'Tournament entrants moving into a state: registered, incomplete, checked_in, withdrawn, disqualified.',
                ['brand', 'state'],
            ),
            new MetricDefinition(
                'competition_matches_total',
                MetricKind::Counter,
                'Tournament matches announced as scheduled (again on a reschedule) and finished (once per match).',
                ['brand', 'state'],
            ),
            new MetricDefinition(
                'competition_disputes_open',
                MetricKind::Gauge,
                'Disputed tournament matches the desk has not decided, in running competitions.',
                ['brand'],
            ),
            new MetricDefinition(
                'competition_score_entries_unverified',
                MetricKind::Gauge,
                'Leaderboard score entries nobody has verified or rejected, in competitions that are not over.',
                ['brand'],
            ),
        ];
    }
}
