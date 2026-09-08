<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Models\MessageRecord;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry in a message's own resend history {@see MessageLogDetailData}:
 * every later record whose `resent_from_id` points back at it, newest
 * first.
 */
#[TypeScript]
final class MessageResendRowData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $queuedAt,
        public readonly MessageStatus $status,
    ) {}

    public static function ofModel(MessageRecord $record): self
    {
        return new self(
            id: $record->getKey(),
            // ISO instants (#238): the console renders them in the reader's
            // own zone through `formatDateTime()` (#208), never in UTC.
            queuedAt: $record->queued_at->toIso8601String(),
            status: $record->status,
        );
    }
}
