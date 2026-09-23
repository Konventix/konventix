<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

/**
 * One match's time, set at the desk (D-CMP-21).
 *
 * The page sends an instant (`toInstant()` in the reader's zone), and
 * `scheduledAtInstant()` normalises whatever offset arrives to UTC before it
 * reaches a zone-less column (.ai/rules/js.md).
 */
final class ScheduleMatchData extends Data
{
    public function __construct(
        #[Required, Date]
        public readonly string $scheduledAt,
    ) {}

    public function scheduledAtInstant(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->scheduledAt)->utc();
    }
}
