<?php

declare(strict_types=1);

namespace Konventix\Community\Data;

use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the moderation console sends to mute somebody (spec §4.3, D-COM-08).
 *
 * **`reason` is required, and that is the design.** A silent mute reads as
 * a broken app and produces a support request instead of a behaviour
 * change, so the reason is shown to the muted person — "Du kannst 10
 * Minuten nicht schreiben: Spam". The column is `NOT NULL` at the database
 * level too (spec §4.3); this is the layer that gives the operator a
 * sentence instead of a constraint violation.
 *
 * `minutes` null means "for the rest of the event" — `expires_at` stays
 * null and the mute holds until somebody lifts it or the room closes. The
 * upper bound is a week: a mute meant to outlive an event is the `null`
 * case, and an unbounded integer here is only ever a typo on its way to
 * `addMinutes()`.
 *
 * The 255 on `reason` is `community_room_mutes.reason`'s own `varchar`
 * length — this is a sentence a participant reads, not an incident report.
 */
#[TypeScript]
final class MuteData extends Data
{
    /**
     * The `varchar` length of `community_room_mutes.reason` (spec §4.3).
     */
    public const int MAX_REASON_LENGTH = 255;

    /**
     * One week. Longer than any event, shorter than a typo.
     */
    public const int MAX_MINUTES = 10080;

    public function __construct(
        #[Required, StringType, Min(1), Max(self::MAX_REASON_LENGTH)]
        public readonly string $reason,
        #[Nullable, IntegerType, Min(1), Max(self::MAX_MINUTES)]
        public readonly ?int $minutes = null,
    ) {}
}
