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
 */
final class ProjectRegistrant
{
    public function handle(UserRegistered|UserProfileUpdated $event): void
    {
        Registrant::query()->updateOrCreate(
            ['id' => $event->userId],
            ['display_name' => $event->displayName, 'email' => $event->email],
        );
    }
}
