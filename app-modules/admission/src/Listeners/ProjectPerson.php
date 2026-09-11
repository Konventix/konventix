<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Models\Person;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;

/**
 * Projects Identity's account events into the person read model (upsert —
 * idempotent and replayable), mirroring Registration's `ProjectRegistrant`
 * and Messaging's `ProjectRecipient`. Registered in
 * `AdmissionServiceProvider::registerListeners()` for both events, and
 * backfilled by the existing `identity:replay-profiles` (it re-emits
 * `UserProfileUpdated`, which this listener already handles).
 *
 * The `Konventix\Identity\Events\` imports are the sanctioned exception
 * (ADR-0004).
 */
final class ProjectPerson
{
    public function handle(UserRegistered|UserProfileUpdated $event): void
    {
        Person::query()->updateOrCreate(
            ['id' => $event->userId],
            ['display_name' => $event->displayName, 'email' => $event->email],
        );
    }
}
