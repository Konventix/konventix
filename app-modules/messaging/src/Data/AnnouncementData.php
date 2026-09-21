<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;

/**
 * The `messaging.announcement` template's payload (ADR-0041 point 8,
 * D-CNT-08): the announcement's title is the notification — the article
 * itself stays in Content. `url` is the entry's own page (`content.show`,
 * `/news/{slug}`), absolute against the brand's host: the in-app and push
 * channels read it off the payload as the notification's link, and the
 * mail template prints it as a guarded "Read the article" line. Null only
 * when Content's public route is not registered.
 */
final class AnnouncementData extends Data
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $url = null,
    ) {}
}
