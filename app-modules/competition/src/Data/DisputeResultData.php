<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

/**
 * A side contests a result (design §8.4). The reason is mandatory — a
 * dispute without one is a stalled match and a desk with nothing to go on —
 * and lands in the match history the desk decides from.
 */
final class DisputeResultData extends Data
{
    public function __construct(
        #[Required, StringType, Max(500)]
        public readonly string $reason,
    ) {}
}
