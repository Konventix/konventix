<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One occupying booking behind a conflict (design §6.1 step 4) — enough for
 * the dialog to say "registration …, claim …" and for the resolution to
 * name what it releases. Never a holder name (design §7.4).
 */
#[TypeScript]
final class PlanConflictBookingData extends Data
{
    public function __construct(
        public readonly string $bookingId,
        public readonly string $holderType,
        public readonly ?string $registrationId,
        public readonly ?string $claimId,
    ) {}
}
