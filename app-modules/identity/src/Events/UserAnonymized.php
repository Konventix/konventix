<?php

declare(strict_types=1);

namespace Konventix\Identity\Events;

/**
 * Identity's own records for a subject are scrubbed (SPEC.md "Public
 * contract").
 *
 * The account row survives with its ULID — anonymize-with-tombstone (Round 6
 * D-BATCH) — so every module holding a `*_user_id` still resolves to
 * something. What is gone is everything that identified the person: name,
 * email, credentials, sessions.
 *
 * Modules keeping a display-name read model of their own (Content's authors,
 * Messaging's recipients) discharge their own part through their `Gdpr/`
 * handler rather than by listening here; this event is the announcement, not
 * the mechanism.
 */
final class UserAnonymized
{
    public function __construct(
        public readonly string $userId,
        public readonly string $anonymizedAt,
    ) {}
}
