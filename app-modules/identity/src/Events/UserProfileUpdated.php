<?php

declare(strict_types=1);

namespace Konventix\Identity\Events;

/**
 * Account contact/profile data changed (SPEC.md "Public contract").
 * Consumers maintaining read models (Messaging recipients, future
 * registrant/holder models) re-project from this payload — idempotently,
 * since a replay carries the same current values. Scalars only (ADR-0004).
 * Widened by ADR-0051 §6: the profile-of-record travels here so per-module
 * read models can render it without reading Identity. Widened again by
 * ADR-0052 §5: `visibility` carries the enum's scalar value — operator-wide,
 * because `profile_visibility` lives on `User` like `username` does, not on
 * the brand membership. Widened a third time by ADR-0064 §6:
 * `listedInAttendees` is the person's opt-in to be named on an event's
 * attendee list — operator-wide for the same reason, and the only way
 * Registration's registrant read model may learn it.
 */
final readonly class UserProfileUpdated
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $displayName,
        public string $username,
        public ?string $tagline,
        public ?string $statusEmoji,
        /** @var array<string, string> */
        public array $socialLinks,
        public string $visibility,
        public bool $listedInAttendees,
        // ADR-0068 §4: null follows the brand.
        public ?string $locale,
        public string $updatedAt,
    ) {}
}
