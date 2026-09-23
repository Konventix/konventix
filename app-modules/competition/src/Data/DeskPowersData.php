<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Which of the desk's four capabilities the viewer holds (design §9, §11).
 * The page hides what it may not do; every endpoint checks again.
 */
#[TypeScript]
final class DeskPowersData extends Data
{
    public function __construct(
        public readonly bool $checkInEntrants,
        public readonly bool $recordResults,
        public readonly bool $correctResults,
        public readonly bool $manageSchedule,
    ) {}
}
