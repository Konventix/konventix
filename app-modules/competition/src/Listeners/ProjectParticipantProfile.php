<?php

declare(strict_types=1);

namespace Konventix\Competition\Listeners;

use Konventix\Competition\Models\ParticipantProfile;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;

/**
 * Keeps Competition's name read model current (design §10).
 *
 * This is the **only** way a person's name enters this module. Every
 * participant and public page reads `ParticipantProfile`; importing
 * Identity's `User` would couple the two modules in the direction ADR-0004
 * forbids, and an architecture test asserts the import never appears.
 *
 * The `Konventix\Identity\Events\` imports here are the sanctioned exception:
 * events are the public contract between modules.
 *
 * Upsert semantics make it idempotent and replayable — a replay carries the
 * then-current values and converges on the same row, which is what lets the
 * read model be rebuilt from scratch. Registered explicitly in
 * `CompetitionServiceProvider::registerListeners()`; modular's event
 * discovery is off and must stay off (.ai/rules/listeners.md).
 *
 * `UserRegistered` predates ADR-0052 and carries no visibility, so that
 * branch writes the same `internal` default Identity's own column ships
 * with; `UserProfileUpdated` carries the profile of record and writes all
 * three fields.
 *
 * Deliberately **not** gated on the Competition feature flag, for the same
 * reason the eligibility projection is not (ADR-0003): a brand switching
 * tournaments on mid-event must find the names already there, or every
 * roster would read as dashes until each person edited their profile.
 */
final class ProjectParticipantProfile
{
    public function handle(UserRegistered|UserProfileUpdated $event): void
    {
        $attributes = $event instanceof UserProfileUpdated
            ? [
                'display_name' => $event->displayName,
                'username' => $event->username,
                'visibility' => $event->visibility,
            ]
            : [
                'display_name' => $event->displayName,
                'username' => $event->username,
                'visibility' => 'internal',
            ];

        ParticipantProfile::query()->updateOrCreate(['user_id' => $event->userId], $attributes);
    }
}
