<?php

declare(strict_types=1);

namespace Konventix\Content\Listeners;

use Konventix\Content\Models\Author;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;

/**
 * Projects Identity's account events into Content's author read model
 * (SPEC.md "Consumes"). Upsert semantics make it idempotent and replayable:
 * a replay carries the then-current values and converges on the same row.
 * Registered explicitly in `ContentServiceProvider::registerListeners()` for
 * both events — discovery is off and must stay off (.ai/rules/listeners.md).
 *
 * The `Konventix\Identity\Events\` imports are the sanctioned exception:
 * events are the public contract between modules (ADR-0004).
 *
 * The email in the payload is deliberately dropped — a byline needs a name,
 * not an address.
 */
final class ProjectAuthor
{
    public function handle(UserRegistered|UserProfileUpdated $event): void
    {
        Author::query()->updateOrCreate(
            ['id' => $event->userId],
            ['display_name' => $event->displayName],
        );
    }
}
