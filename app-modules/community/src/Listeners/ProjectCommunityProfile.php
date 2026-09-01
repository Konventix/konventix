<?php

declare(strict_types=1);

namespace Konventix\Community\Listeners;

use Konventix\Community\Models\CommunityProfile;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;

/**
 * Projects Identity's account events into Community's operator-wide profile
 * read model (SPEC.md "Owns" — community profile). Upsert semantics make it
 * idempotent and replayable: a replay carries the then-current values and
 * converges on the same row. Registered explicitly in
 * `CommunityServiceProvider::registerListeners()` for both events —
 * discovery is off and must stay off (.ai/rules/listeners.md).
 *
 * The `Konventix\Identity\Events\` imports are the sanctioned exception:
 * events are the public contract between modules (ADR-0004).
 *
 * `UserRegistered` only carries username and display name, so that branch
 * writes those two fields; `UserProfileUpdated` carries the full profile and
 * writes all five, with an empty `socialLinks` array normalized to null.
 */
final class ProjectCommunityProfile
{
    public function handle(UserRegistered|UserProfileUpdated $event): void
    {
        $attributes = $event instanceof UserProfileUpdated
            ? [
                'username' => $event->username,
                'display_name' => $event->displayName,
                'tagline' => $event->tagline,
                'status_emoji' => $event->statusEmoji,
                'social_links' => $event->socialLinks === [] ? null : $event->socialLinks,
            ]
            : ['username' => $event->username, 'display_name' => $event->displayName];

        CommunityProfile::query()->updateOrCreate(['id' => $event->userId], $attributes);
    }
}
