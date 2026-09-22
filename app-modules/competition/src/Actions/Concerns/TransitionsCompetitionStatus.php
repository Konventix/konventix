<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Exceptions\IllegalCompetitionStateTransitionException;
use Konventix\Competition\Models\Competition;

/**
 * The one place a competition's lifecycle state is written (D-CMP-15,
 * design §4).
 *
 * Every lifecycle Action goes through here, so the transition table on
 * `CompetitionStatus` is consulted exactly once per move and no Action can
 * quietly grow its own idea of what is legal. The row is locked first: two
 * organizers clicking "start" at the same moment must not both read
 * `SignupClosed` and both emit `CompetitionStarted`.
 *
 * `BrandScope` is escaped on the lock query because the console runs on the
 * admin host, where it hardens to zero rows (ADR-0029). `refresh()` needs no
 * escape — it queries without global scopes by itself.
 */
trait TransitionsCompetitionStatus
{
    /**
     * @param  array<string, mixed>  $attributes  written in the same save as the new state
     */
    protected function transition(
        Competition $competition,
        CompetitionStatus $target,
        array $attributes = [],
    ): Competition {
        Competition::withoutBrandScope()->whereKey($competition->getKey())->lockForUpdate()->firstOrFail();
        $competition->refresh();

        if (! $competition->status->canTransitionTo($target)) {
            throw IllegalCompetitionStateTransitionException::forTransition(
                $competition->id,
                $competition->status,
                $target,
            );
        }

        $competition->forceFill(['status' => $target, ...$attributes])->save();

        return $competition;
    }
}
