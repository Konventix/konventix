<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Konventix\Messaging\Models\InboxNotification;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One inbox notification as the bell sees it (ADR-0041 point 6) — one
 * `Data` class as serialization and generated TS type at once (ADR-0009).
 * Shape-identical to `NotificationReceived::broadcastWith()`, so the bell
 * treats a fetched row and a pushed one interchangeably.
 */
#[TypeScript]
final class NotificationData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $topicKey,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url,
        public readonly ?string $readAt,
        public readonly string $createdAt,
    ) {}

    public static function ofModel(InboxNotification $notification): self
    {
        return new self(
            id: $notification->getKey(),
            topicKey: $notification->topic_key,
            title: $notification->title,
            body: $notification->body,
            url: $notification->url,
            readAt: $notification->read_at?->toIso8601String(),
            createdAt: $notification->created_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }
}
