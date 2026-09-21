<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Spatie\LaravelData\Data;

/**
 * The typed payload behind `support.request-link` (design §8.2): a fresh
 * 30-day link to a guest's own report, after the first one expired.
 *
 * Two fields, because there is nothing else to say. The mail goes to the
 * address stored on the row and never to one somebody typed into the
 * re-issue form, so it cannot be used to move a report to a new inbox —
 * and the page that triggered it never learns whether it was sent at all.
 */
final class SupportLinkData extends Data
{
    public function __construct(
        public readonly string $reference,
        /** Absolute, signed, valid for another 30 days. */
        public readonly string $requestUrl,
    ) {}
}
