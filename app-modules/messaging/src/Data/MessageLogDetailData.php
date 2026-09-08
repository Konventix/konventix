<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Models\MessageRecord;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One delivery-log row in full (ADR-0048 decision 6): everything the index
 * row carries, plus the payload, the render and its attachments.
 *
 * Seeing that a message was sent (`ViewMessageLog`) and reading what it
 * said (`ViewMessageContent`) are separate capabilities (SPEC.md
 * "Capabilities") — a viewer without the second gets `bodyRedacted: true`
 * and no subject, body, or payload, even on a row that stored one. The
 * payload is exactly as much personal data as the rendered body — the
 * template variables it was rendered from — so it is gated the same way,
 * not merely "less sensitive because it isn't prose".
 */
#[TypeScript]
final class MessageLogDetailData extends Data
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @param  list<string>  $attachments  file names only ("as"), never the disk path
     * @param  list<MessageResendRowData>  $resends
     */
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
        public readonly ?array $payload,
        public readonly ?string $renderedSubject,
        public readonly ?string $renderedBody,
        public readonly bool $bodyRedacted,
        public readonly array $attachments,
        public readonly int $attempts,
        public readonly ?string $topicKey,
        public readonly bool $urgent,
        public readonly ?string $providerMessageId,
        public readonly array $resends,
    ) {}

    /**
     * @param  list<MessageResendRowData>  $resends
     */
    public static function ofModel(MessageRecord $record, string $brandName, bool $canViewContent, array $resends): self
    {
        $attachments = array_values(collect($record->attachments ?? [])
            ->map(function (array $attachment): string {
                $name = $attachment['as'] ?? $attachment['path'] ?? null;

                return is_string($name) ? $name : 'attachment';
            })
            ->all());

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
            lastError: $record->last_error,
            resentFromId: $record->resent_from_id,
            // Gated exactly like the render below — the payload is the
            // data the render came from, not a lesser category of it.
            payload: $canViewContent ? $record->payload : null,
            renderedSubject: $canViewContent ? $record->rendered_subject : null,
            renderedBody: $canViewContent ? $record->rendered_body : null,
            bodyRedacted: ! $canViewContent,
            attachments: $attachments,
            attempts: $record->attempts,
            topicKey: $record->topic_key,
            urgent: $record->urgent,
            providerMessageId: $record->provider_message_id,
            resends: $resends,
        );
    }
}
