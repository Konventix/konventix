<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The operator's answer to one conflict (design §6.1, §6.3). `release` is
 * the only resolution in v1: the occupying booking is released with reason
 * `plan_conflict` and the claim goes back to `unplaced`. `assign_manual` is
 * the Phase 3 issue and arrives as a second `In` value, not a new class.
 */
#[TypeScript]
final class PlanConflictResolutionData extends Data
{
    public const string RELEASE = 'release';

    public function __construct(
        #[Required, Ulid]
        public readonly string $placeId,

        #[Required, StringType, In([self::RELEASE])]
        public readonly string $resolution = self::RELEASE,
    ) {}
}
