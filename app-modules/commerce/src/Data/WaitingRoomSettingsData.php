<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Models\WaitingRoomSettings;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The waiting room's per-brand settings form (ADR-0047 point 5,
 * `WaitingRoomSettingsAdminController::update()`) — how many arrivals are
 * admitted per call, and how long that admission lasts. Also the shape the
 * brand edit page's Waiting room card reads its current values from
 * (`WaitingRoomSettings::forBrand()`'s result, via `ofModel()`).
 */
#[TypeScript]
final class WaitingRoomSettingsData extends Data
{
    public function __construct(
        #[Required, IntegerType, Min(1), Max(1000)]
        public readonly int $admitPerInterval,
        #[Required, IntegerType, Min(1), Max(240)]
        public readonly int $admissionTtlMinutes,
    ) {}

    public static function ofModel(WaitingRoomSettings $settings): self
    {
        return new self(
            admitPerInterval: $settings->admit_per_interval,
            admissionTtlMinutes: $settings->admission_ttl_minutes,
        );
    }
}
