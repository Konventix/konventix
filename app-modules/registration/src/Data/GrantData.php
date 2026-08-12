<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Enums\GrantKind;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;

/**
 * One entry in a `RegistrationType`'s `grants` collection (ADR-0005, SPEC.md
 * "Grant definitions" — "how many admissions of which role, and which
 * resource claims, a confirmed registration of a given type is entitled
 * to"). `RegistrationType::$grants` casts its JSON column to a
 * `Spatie\LaravelData\DataCollection` of these (ADR-0009 — a JSON column is
 * acceptable only behind a typed cast).
 *
 * `resourceTypeId` only applies to `GrantKind::ResourceClaim`; it stays a
 * plain nullable ULID (no FK) because Resources owns that entity (ADR-0014).
 */
final class GrantData extends Data
{
    public function __construct(
        #[Required]
        public readonly GrantKind $kind,

        #[Required, StringType]
        public readonly string $role,

        #[Required, IntegerType, Min(1)]
        public readonly int $quantity,

        #[Nullable, Ulid]
        public readonly ?string $resourceTypeId = null,
    ) {}
}
