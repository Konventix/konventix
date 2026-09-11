<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Enums\GrantKind;
use Konventix\Registration\Enums\SeatingMode;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry in a `RegistrationType`'s `grants` collection (ADR-0005, SPEC.md
 * "Grant definitions" — "how many admissions of which role, which resource
 * claims, and which seating a confirmed registration of a given type is
 * entitled to"). `RegistrationType::$grants` casts its JSON column to a
 * `Spatie\LaravelData\DataCollection` of these (ADR-0009 — a JSON column is
 * acceptable only behind a typed cast).
 *
 * `resourceTypeId` only applies to `GrantKind::ResourceClaim`;
 * `resourceTypeIds` and `seatingMode` only to `GrantKind::Seating`, where
 * `quantity` is the maximum number of places (seating design §7.1). All of
 * them are plain ULIDs (no FK) because Resources owns those entities
 * (ADR-0014). Which fields a kind needs is a cross-field rule and lives in
 * `Actions\Concerns\GuardsSeatingGrants`, not here.
 */
#[TypeScript]
final class GrantData extends Data
{
    /**
     * @param  list<string>|null  $resourceTypeIds
     */
    public function __construct(
        #[Required]
        public readonly GrantKind $kind,

        #[Required, StringType]
        public readonly string $role,

        #[Required, IntegerType, Min(1)]
        public readonly int $quantity,

        #[Nullable, Ulid]
        public readonly ?string $resourceTypeId = null,

        /** @var list<string>|null */
        #[Nullable, ArrayType]
        public readonly ?array $resourceTypeIds = null,

        #[Nullable]
        public readonly ?SeatingMode $seatingMode = null,
    ) {}

    /**
     * Only the element rule: laravel-data replaces the inferred ruleset for
     * every key this method returns (`RegistrationTypeData::rules()` explains
     * the trap), and `resourceTypeIds.*` has no inferred rules to lose.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'resourceTypeIds.*' => ['ulid'],
        ];
    }

    /**
     * The scalar shape `RegistrationConfirmed` and `RegistrationAmended`
     * carry (SPEC.md "Public contract"). Consumers key on the strings, never
     * on this class (ADR-0004), so the keys here are the contract.
     *
     * Not a `from*` name on purpose (.ai/rules/data.md).
     *
     * @return array{kind: string, role: string, quantity: int, resource_type_id: string|null, resource_type_ids: list<string>|null, seating_mode: string|null}
     */
    public function toEventPayload(): array
    {
        return [
            'kind' => $this->kind->value,
            'role' => $this->role,
            'quantity' => $this->quantity,
            'resource_type_id' => $this->resourceTypeId,
            // Already a list<string>|null (the constructor's own PHPDoc and
            // validation), so no re-indexing is needed here.
            'resource_type_ids' => $this->resourceTypeIds,
            'seating_mode' => $this->seatingMode?->value,
        ];
    }
}
