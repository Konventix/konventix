<?php

declare(strict_types=1);

namespace Konventix\Identity\Events;

/**
 * A member's brand-facing face changed — avatar and/or banner (ADR-0051
 * §1), or the membership itself was first established (ruling 2: "member
 * since" must be replayable, so the event carries `firstSeenAt` even when
 * nothing else changed yet). Consumers maintaining read models re-project
 * from this payload — idempotently, since a replay carries the same
 * current values. Scalars only (ADR-0004).
 */
final readonly class BrandMembershipProfileUpdated
{
    public function __construct(
        public string $userId,
        public string $brandId,
        public ?string $avatarUrl,
        public ?string $bannerUrl,
        public string $firstSeenAt,
        public string $updatedAt,
    ) {}
}
