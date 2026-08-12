<?php

declare(strict_types=1);

namespace Konventix\Messaging\Listeners;

use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Models\Recipient;

/**
 * Projects Identity's account events into the recipient read model
 * (SPEC.md "Consumes"). Upsert semantics make it idempotent and
 * replayable: a replay carries the then-current values and converges on
 * the same row. Registered in
 * `MessagingServiceProvider::registerListeners()` for both events.
 *
 * The `Konventix\Identity\Events\` imports are the sanctioned exception:
 * events are the public contract between modules (ADR-0004).
 */
final class ProjectRecipient
{
    public function handle(UserRegistered|UserProfileUpdated $event): void
    {
        Recipient::query()->updateOrCreate(
            ['id' => $event->userId],
            [
                'email' => $event->email,
                'display_name' => $event->displayName,
                'locale' => $event->locale,
            ],
        );
    }
}
