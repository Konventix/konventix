<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

/**
 * Emitted when an export request is accepted and fans out (SPEC "Public
 * contract"). Every registered GdprHandler is expected to react to this and
 * contribute its export data; handlers must be idempotent so this event may
 * be re-dispatched safely (see RedispatchSubjectRequest).
 */
final class DataExportRequested
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $subjectId,
        public readonly ?string $subjectEmail,
        public readonly string $brandId,
        public readonly string $requestedAt,
    ) {}
}
