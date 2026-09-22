<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;

/**
 * One submitted score on a leaderboard stage (design §7.2, §8.3, ADR-0009).
 *
 * What a participant (or the desk) says; **not** whether it counts. Verifying
 * is a separate Action with its own capability, and the fields it writes —
 * `verified_at`, `verified_by_user_id` — are deliberately absent here: a form
 * that could set them would be a second, unguarded way past the verification
 * gate.
 *
 * `value` is a plain float because a leaderboard's number is a measurement —
 * a time in seconds, a point total, a jury score — and not money. The column
 * behind it is `decimal(12,3)`.
 */
final class ScoreEntryData extends Data
{
    public function __construct(
        #[Required, Numeric]
        public readonly float $value,

        /** 1..`heats` on a stage with heats, and null on a stage without. */
        #[Nullable, IntegerType, Min(1), Max(64)]
        public readonly ?int $heat = null,

        /** Platform media (ADR-0035); the policy decides whether it is owed. */
        #[Nullable, Ulid]
        public readonly ?string $proofMediaId = null,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $note = null,
    ) {}
}
