<?php

declare(strict_types=1);

namespace Konventix\Identity\Sessions;

/**
 * One active session, as the Security page needs it, independent of where
 * it was stored (D-IDP-17): a `sessions` row on the database driver, a meta
 * hash in redis on the indexed redis driver.
 *
 * `lastActivity` is the Unix timestamp both storages already keep, not a
 * Carbon instance — `SessionData::fromSummary()` is the one place it turns
 * into an ISO string for the page.
 */
final readonly class SessionSummary
{
    public function __construct(
        public string $id,
        public ?string $ipAddress,
        public ?string $userAgent,
        public int $lastActivity,
    ) {}
}
