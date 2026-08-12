<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
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
    ) {}
}
