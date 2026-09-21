<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Konventix\Support\Enums\MessageVisibility;
use Konventix\Support\Models\SupportRequestMessage;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry of the thread as a surface renders it (ADR-0009).
 *
 * `visibility` travels with the message rather than being filtered out at
 * the query: the console shows both kinds and marks which is which, and the
 * reporter-facing pages ask for external ones only. Two shapes for the same
 * row would be two places to get that filter wrong.
 *
 * `isFromReporter` is derived here rather than left to each template — the
 * guest reporter has no account, so "no author" and "the reporter" are the
 * same fact and only this module knows that.
 */
#[TypeScript]
final class SupportRequestMessageData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $authorUserId,
        public readonly bool $isFromReporter,
        public readonly MessageVisibility $visibility,
        public readonly string $body,
        public readonly ?string $createdAt,
    ) {}

    public static function fromModel(SupportRequestMessage $message): self
    {
        return new self(
            id: $message->getKey(),
            authorUserId: $message->author_user_id,
            isFromReporter: $message->author_user_id === null,
            visibility: $message->visibility,
            body: $message->body,
            createdAt: $message->created_at?->toIso8601String(),
        );
    }
}
