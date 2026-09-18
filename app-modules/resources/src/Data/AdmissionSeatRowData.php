<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One ticket of the registration in the seat summary (#292 §6.4): which
 * place it sits on, if any. Resources knows the holder only by id — the
 * ticket manager beside this summary names the people — so the row is
 * numbered, not named.
 */
#[TypeScript]
final class AdmissionSeatRowData extends Data
{
    public function __construct(
        public readonly string $admissionId,
        public readonly int $number,
        public readonly ?string $placeRef,
        public readonly bool $isHeld,
    ) {}
}
