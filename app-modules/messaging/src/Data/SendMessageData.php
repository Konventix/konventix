<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * The queued envelope for a single accepted send: `SendMessageRequest`
 * after resolution — the id generated at accept time, the brand it was
 * accepted for, and the concrete email/locale (read model or explicit
 * override, resolved in `SendMessage` so the queue never re-resolves).
 *
 * This is what rides across the queue boundary as `SendQueuedMessage`'s job
 * payload. Plain readonly value objects serialize with PHP's native job
 * serialization — no custom Wireable/queueable machinery needed.
 */
final class SendMessageData extends Data
{
    /**
     * @param  DataCollection<int, AttachmentData>|null  $attachments
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $brandId,
        public readonly ?string $recipientId,
        public readonly string $recipientEmail,
        public readonly string $recipientLocale,
        public readonly string $templateKey,
        public readonly Data $payload,
        public readonly string $channel,
        public readonly ?DataCollection $attachments = null,
    ) {}
}
