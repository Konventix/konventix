<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a participant sends when they report a line (spec §4.4, D-COM-01).
 *
 * **One optional field and no category list.** D-COM-01 settles that the
 * answer to moderation here is a queue a human works, not a taxonomy: the
 * desk can already read the line being reported, and a required "why"
 * would only buy a reporter's guess at a label and cost the reports nobody
 * files because the form asked a question they could not answer.
 *
 * The note is the reporter's own words. It never travels on
 * `ShoutboxMessageReported` (spec §9) for the same reason the message body
 * does not: an event payload is not a place to copy user-generated text.
 *
 * No `messageId` here — which message is being reported is a route
 * parameter the desk's own gate resolves, not a field the client names.
 */
#[TypeScript]
final class ReportData extends Data
{
    /**
     * The `varchar` length of `community_message_reports.note` (spec §4.4).
     */
    public const int MAX_NOTE_LENGTH = 255;

    public function __construct(
        #[Nullable, StringType, Max(self::MAX_NOTE_LENGTH)]
        public readonly ?string $note = null,
    ) {}
}
