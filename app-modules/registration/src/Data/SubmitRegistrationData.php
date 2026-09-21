<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The single source of validation rules, Inertia props shape, and generated
 * TypeScript type for `POST /events/{event}/register/{type}` (ADR-0009).
 *
 * The invoice recipient may be an organization rather than the submitting
 * user (SPEC.md "Owns" — `Registration`), so every field here is nullable
 * at the type level: a plain visitor registration answers none of them. For
 * invoice-settled types the postal-address fields become required — a
 * legally valid German invoice needs the recipient's address — which
 * `SubmitRegistration::guardBillingParty()` enforces per type, since a
 * static `rules()` method cannot see which type the URL addressed.
 *
 * `billingVatId` and `billingCountryCode` feed reverse-charge treatment in
 * Commerce (ADR-0030): both optional — a domestic exhibitor has neither
 * obligation.
 */
#[TypeScript]
final class SubmitRegistrationData extends Data
{
    public function __construct(
        #[Nullable, StringType, Max(255)]
        public readonly ?string $organizationName,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $billingRecipientName,

        #[Nullable, Email, Max(255)]
        public readonly ?string $billingEmail,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $billingAddressLine = null,

        #[Nullable, StringType, Max(16)]
        public readonly ?string $billingPostalCode = null,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $billingCity = null,

        #[Nullable, StringType, Size(2)]
        public readonly ?string $billingCountryCode = null,

        #[Nullable, StringType, Max(32)]
        public readonly ?string $billingVatId = null,

        /**
         * How many units of the offer this submission buys (bugfix round
         * 2026-09-21, Task 19).
         *
         * `Min(1)` and a default of one: a form that sends nothing — every
         * type whose ceiling is one, which is every type that has not opted
         * in — buys one unit, exactly as before. The *upper* bound is the
         * type's own `max_quantity_per_registration` and what its quotas
         * still have left, neither of which a static ruleset can see, so
         * `SubmitRegistration` guards it per type the way
         * `guardBillingParty()` guards the invoice address.
         */
        #[IntegerType, Min(1)]
        public readonly int $quantity = 1,

        /**
         * The entry conditions this submission declares (ADR-0066, spec
         * §6.2): one id per checkbox the registrant ticked.
         *
         * *Which* ones are owed cannot be written down here — a static
         * ruleset cannot see which type the URL addressed — so
         * `SubmitRegistration::guardEntryConditions()` enforces the type's
         * own set, exactly as `guardBillingParty()` enforces the invoice
         * address. What this list is, is the declaration itself; an id the
         * type does not demand is ignored rather than refused.
         *
         * @var list<string>
         */
        #[ArrayType]
        public readonly array $acceptedEntryConditionIds = [],

        /**
         * Whether the legal texts shown on the form were accepted (D-CPL-10,
         * spec §6.2). Registration records the declaration and emits
         * `RegistrationConsentGiven`; which texts those were, and whether any
         * of them needed a tick at all, is Compliance's to resolve from the
         * binding context (Task 32). This module never reads a policy.
         */
        #[BooleanType]
        public readonly bool $acceptedLegalTexts = false,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            // Absent is fine — a type that demands no declaration sends
            // nothing — and present must be an array, empty included. The
            // inferred ruleset would make a non-nullable array `required`,
            // which refuses exactly that empty array (the trap
            // `RegistrationTypeData::rules()` documents).
            'acceptedEntryConditionIds' => ['sometimes', 'array'],
            'acceptedEntryConditionIds.*' => ['ulid'],
            'acceptedLegalTexts' => ['sometimes', 'boolean'],
        ];
    }
}
