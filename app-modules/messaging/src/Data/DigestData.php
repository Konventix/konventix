<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;

/**
 * The `messaging.digest` template's payload (ADR-0041 point 5): several
 * held messages for one (recipient, channel) coalesce into one message
 * listing each item's rendered subject, in the recipient's locale.
 */
final class DigestData extends Data
{
    /**
     * @param  list<string>  $items  each held message's rendered subject
     */
    public function __construct(
        public readonly int $count,
        public readonly array $items,
    ) {}
}
