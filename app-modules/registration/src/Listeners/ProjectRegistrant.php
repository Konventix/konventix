<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners;

use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Registration\Models\Registrant;

/**
 * Projects Identity's account events into the registrant read model
 * (upsert — idempotent and replayable), mirroring Messaging's
 * `ProjectRecipient`. The `Konventix\Identity\Events\` imports are the
 * sanctioned exception (ADR-0004).
 *
 * Since ADR-0064 §6 the row also carries the two facts the attendee list
 * needs: the `username` a name on the list *is*, and `listed`, the
 * person's opt-in to be named. Both events carry the handle;
 * only `UserProfileUpdated` carries the opt-in, so `UserRegistered` leaves
 * `listed` at the column's default (false) rather than writing a value it
 * was never told — a fresh account has not opted in, and a replay of the
 * profile event is what converges the column.
 */
final class ProjectRegistrant
{
    public function handle(UserRegistered|UserProfileUpdated $event): void
    {
        $attributes = [
            'display_name' => $event->displayName,
            'email' => $event->email,
            'username' => $event->username,
        ];

        if ($event instanceof UserProfileUpdated) {
            $attributes['listed'] = $event->listedInAttendees;
        }

        Registrant::query()->updateOrCreate(['id' => $event->userId], $attributes);
    }
}
