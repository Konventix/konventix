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
use Spatie\LaravelData\Attributes\Validation\Date;
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

        /**
         * The sales window (#218, #216 draft i). Plain `Y-m-d H:i:s` strings
         * rather than Carbon instances, exactly as `EventData` carries its
         * own datetimes: the form's `datetime-local` control round-trips a
         * string, and the model's `datetime` cast does the parsing.
         *
         * These are instants in the application timezone (UTC), not
         * wall-clock times — the migration's docblock says why a sales window
         * is not an ADR-0027 event time.
         */
        #[Nullable, Date]
        public readonly ?string $salesStart = null,

        #[Nullable, Date]
        public readonly ?string $salesEnd = null,
    ) {}

    /**
     * Every entry here is written out in full, on purpose.
     *
     * laravel-data 4.x **replaces** the inferred ruleset for any key this
     * method returns rather than merging into it
     * (`DataValidationRulesResolver`: `$shouldMergeRules ? merge() : add()`,
     * and this class carries no `#[MergeValidationRules]`). Returning the
     * cross-field rule alone therefore silently dropped `nullable` and
     * `date` from `salesEnd`, and the admin form — which transforms on every
     * submit and so always sends both keys, null when the input is empty —
     * could no longer save *any* type, windowed or not (fix round 1,
     * review finding 1). `EventData::rules()` gets away with the short form
     * only because its `endsAt` is required and always a date.
     *
     * `quotaIds` is here for the same reason: the inferred ruleset makes a
     * non-nullable array `required`, and Laravel's `required` rejects the
     * empty array the form sends when no quota is ticked.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'salesStart' => ['nullable', 'date'],
            // `after` is cross-field, so it cannot live on the `salesEnd`
            // attribute alone. A window that ends before it starts is never
            // on sale, and nothing downstream would say so.
            'salesEnd' => ['nullable', 'date', 'after:salesStart'],
            // Absent is fine (the quick-create form omits it); present must
            // be an array, empty included.
            'quotaIds' => ['sometimes', 'array'],
        ];
    }

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
            salesStart: $type->sales_start?->format('Y-m-d H:i:s'),
            salesEnd: $type->sales_end?->format('Y-m-d H:i:s'),
        );
    }
}
