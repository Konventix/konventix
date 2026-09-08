<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Models\MessageRecord;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the delivery-log index (ADR-0048 decision 6): the fields the
 * list shows, not the full render {@see MessageLogDetailData} carries — the
 * payload and rendered body are deliberately absent here, since listing a
 * page of rows is not the moment to also load a page of bodies.
 */
#[TypeScript]
final class MessageLogRowData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $brandName,
        public readonly string $recipientEmail,
        public readonly ?string $recipientId,
        public readonly string $templateKey,
        public readonly Channel $channel,
        public readonly MessageStatus $status,
        public readonly string $queuedAt,
        public readonly ?string $sentAt,
        public readonly ?string $failedAt,
        public readonly ?string $lastError,
        public readonly ?string $resentFromId,
    ) {}

    public static function ofModel(MessageRecord $record, string $brandName): self
    {
        return new self(
            id: $record->getKey(),
            brandName: $brandName,
            recipientEmail: $record->recipient_email,
            recipientId: $record->recipient_id,
            templateKey: $record->template_key,
            channel: $record->channel,
            status: $record->status,
            // ISO instants (#238): the console renders them in the reader's
            // own zone through `formatDateTime()` (#208), never in UTC.
            queuedAt: $record->queued_at->toIso8601String(),
            sentAt: $record->sent_at?->toIso8601String(),
            failedAt: $record->failed_at?->toIso8601String(),
            lastError: $record->last_error === null ? null : mb_substr($record->last_error, 0, 120),
            resentFromId: $record->resent_from_id,
        );
    }
}
