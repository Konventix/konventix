<?php

declare(strict_types=1);

namespace Konventix\Messaging\Events;

/**
 * Contract event (SPEC.md): an in-app notification was marked read.
 * Scalars only (ADR-0004).
 */
final class NotificationRead
{
    public function __construct(
        public readonly string $notificationId,
        public readonly string $recipientId,
        public readonly string $readAt,
    ) {}
}
