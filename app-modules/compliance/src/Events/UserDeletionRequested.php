<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

/**
 * Emitted when a deletion request is accepted and fans out (SPEC "Public
 * contract"). `mode` is DeletionMode::Anonymize->value or
 * DeletionMode::Erase->value. Every registered GdprHandler is expected to
 * react to this; handlers must be idempotent so this event may be
 * re-dispatched safely (see RedispatchSubjectRequest).
 */
final class UserDeletionRequested
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $subjectId,
        public readonly string $brandId,
        public readonly string $mode,
    ) {}
}
