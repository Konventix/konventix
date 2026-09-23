<?php

declare(strict_types=1);

namespace Konventix\Competition\Listeners\Metrics;

use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Events\EntrantCheckedIn;
use Konventix\Competition\Events\EntrantRegistered;
use Konventix\Competition\Events\EntrantWithdrawn;
use Konventix\Competition\Events\MatchFinished;
use Konventix\Competition\Events\MatchScheduled;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;

/**
 * The recorders behind `competition_entrants_total` and
 * `competition_matches_total` (ADR-0059, design §13), on this module's own
 * events.
 *
 * Every event here is dispatched after its transaction commits
 * (`DB::afterCommit()`), so a refused write is never counted. No try/catch:
 * `Metrics` reports a storage failure instead of propagating it. Scoped
 * bindings are resolved inside the handler, never in a constructor
 * (`.ai/rules/listeners.md`).
 */
final class RecordCompetitionMetrics
{
    /**
     * `registered` for a full roster, `incomplete` for one below the team
     * size (§5.4). The event says which.
     */
    public function handleEntrantRegistered(EntrantRegistered $event): void
    {
        $this->entrant($event->brandId, $event->state);
    }

    public function handleEntrantCheckedIn(EntrantCheckedIn $event): void
    {
        $this->entrant($event->brandId, EntrantState::CheckedIn->value);
    }

    /**
     * `withdrawn` or `disqualified`: the state the entrant ended in, not the
     * reason it left.
     */
    public function handleEntrantWithdrawn(EntrantWithdrawn $event): void
    {
        $this->entrant($event->brandId, $event->finalState);
    }

    public function handleMatchScheduled(MatchScheduled $event): void
    {
        $this->match($event->brandId, MatchState::Scheduled->value);
    }

    public function handleMatchFinished(MatchFinished $event): void
    {
        $this->match($event->brandId, MatchState::Finished->value);
    }

    private function entrant(string $brandId, string $state): void
    {
        app(Metrics::class)->increment('competition_entrants_total', [
            'brand' => BrandSlugs::of($brandId),
            'state' => $state,
        ]);
    }

    private function match(string $brandId, string $state): void
    {
        app(Metrics::class)->increment('competition_matches_total', [
            'brand' => BrandSlugs::of($brandId),
            'state' => $state,
        ]);
    }
}
