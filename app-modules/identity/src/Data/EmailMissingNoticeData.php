<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Spatie\LaravelData\Data;

/**
 * Typed payload for `identity.email-missing` (#293, ADR-0009). `url` is the
 * key Messaging's `InAppChannel` lifts into the inbox row as the click
 * target — the page that collects the address.
 */
final class EmailMissingNoticeData extends Data
{
    public function __construct(
        public readonly string $url,
    ) {}
}
