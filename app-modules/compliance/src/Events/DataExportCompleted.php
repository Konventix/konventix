<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

/**
 * Emitted when every registered handler has contributed and the archive
 * exists (SPEC "Public contract"). Messaging notifies the subject from this;
 * the payload carries the expiry rather than a URL, because the download is
 * authorized per request and never handed out as a link with its own life.
 */
final class DataExportCompleted
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $subjectId,
        public readonly ?string $subjectEmail,
        public readonly string $brandId,
        public readonly string $archiveExpiresAt,
    ) {}
}
