<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the compose box sends (spec §6): the single source of the post's
 * validation rules, its Inertia props and its generated TypeScript
 * (ADR-0009).
 *
 * One field, and no `roomId`: which room a participant may write to is
 * Platform's `ActiveEventContext`'s answer, not the client's
 * (D-COM-03) — a Data object that carried a room id would be a client
 * naming the room it wants, which is the one thing the access gate exists
 * to refuse.
 *
 * `MAX_BODY_LENGTH` is the schema's own `varchar(500)` (spec §4.2) and is
 * the constant `PostMessage` re-checks against. **The validation here is
 * the convenience, not the rule**: the cap is enforced again inside the
 * Action, because a client that skips this form must still be refused
 * (spec §6, D-COM-09).
 */
#[TypeScript]
final class PostMessageData extends Data
{
    /**
     * The `varchar(500)` of `community_messages.body` (spec §4.2).
     */
    public const int MAX_BODY_LENGTH = 500;

    public function __construct(
        #[Required, StringType, Min(1), Max(self::MAX_BODY_LENGTH)]
        public readonly string $body,
    ) {}
}
