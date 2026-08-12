<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;

/**
 * The queued envelope for a single accepted send: `SendMessageRequest` plus
 * the identifiers generated when it was accepted (`messageId`) and the brand
 * it was accepted under (`brandId`, read from BrandContext at accept time).
 *
 * This is what rides across the queue boundary as `SendQueuedMessage`'s job
 * payload. Plain readonly value objects (this one, and its nested `payload`
 * Data object) serialize with PHP's native job serialization same as any
 * other job property — no custom Wireable/queueable machinery needed.
 */
final class SendMessageData extends Data
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $brandId,
        public readonly string $recipientId,
        public readonly string $recipientEmail,
        public readonly string $recipientLocale,
        public readonly string $templateKey,
        public readonly Data $payload,
        public readonly string $channel,
    ) {}
}
