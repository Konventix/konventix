<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Models\RegistrationType;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The registration-type form: one source of validation rules, Inertia props
 * and generated TypeScript (ADR-0009) for the offer's four axes (ADR-0005),
 * its grants, its price declaration (ADR-0017) and the quotas it draws on.
 *
 * The *combinations* those axes may form are not validated here. A priced
 * settlement without a price, or an `on_payment` gate on a type nobody ever
 * pays for, is a cross-field invariant that must hold however the type was
 * created — so `CreateRegistrationType`/`UpdateRegistrationType` enforce it
 * through `GuardsTypeConfiguration`, and a tinker session gets the same
 * refusal this form does.
 */
#[TypeScript]
final class RegistrationTypeData extends Data
{
    /**
     * @param  list<GrantData>  $grants
     * @param  list<string>  $quotaIds
     */
    public function __construct(
        #[Required, Ulid]
        public readonly string $eventId,

        #[Required, StringType, Max(255)]
        public readonly string $name,

        #[Required]
        public readonly RegistrationVisibility $visibility,

        #[Required]
        public readonly RegistrationApproval $approval,

        #[Required]
        public readonly RegistrationSettlement $settlement,

        #[Required]
        public readonly ConfirmationGate $confirmationGate,

        #[Required, IntegerType, Min(0), Max(365)]
        public readonly int $termsDays,

        /** @var list<GrantData> */
        #[DataCollectionOf(GrantData::class)]
        public readonly array $grants = [],

        #[Nullable]
        public readonly ?PriceDeclarationData $priceDeclaration = null,

        /** @var list<string> */
        #[ArrayType]
        public readonly array $quotaIds = [],
    ) {}

    public static function fromModel(RegistrationType $type): self
    {
        return new self(
            eventId: $type->event_id,
            name: $type->name,
            visibility: $type->visibility,
            approval: $type->approval,
            settlement: $type->settlement,
            confirmationGate: $type->confirmation_gate,
            termsDays: $type->terms_days,
            grants: array_values($type->grants->all()),
            priceDeclaration: $type->price_declaration,
            quotaIds: array_values($type->quotas->pluck('id')->all()),
        );
    }
}
