<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;

/**
 * One line of a `messaging.digest` (Round 4 Task 27, D-MSG-11): a coalesced
 * member's subject, rendered exactly as an immediate send would have
 * rendered it, and the link that member's own payload carried.
 *
 * `url` is null where the original payload had no `url` field — a digest
 * mixes templates, and only some of them point anywhere. A digested
 * announcement used to lose the `/news/{slug}` link the urgent path and the
 * inbox row carry; the item keeps it now, and the template prints a plain
 * subject where it is null rather than an empty anchor.
 */
final class DigestItemData extends Data
{
    public function __construct(
        public readonly string $subject,
        public readonly ?string $url = null,
    ) {}
}
