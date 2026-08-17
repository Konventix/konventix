<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;

/**
 * The `messaging.announcement` template's payload (ADR-0041 point 8,
 * D-CNT-08): the announcement's title is the notification — the article
 * itself stays in Content. No URL yet: Content ships no public detail
 * route (its TODO), so there is nothing to link to.
 */
final class AnnouncementData extends Data
{
    public function __construct(
        public readonly string $title,
    ) {}
}
