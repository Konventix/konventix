<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Models\Operator;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The operator settings form (ADR-0009): one definition for validation, the
 * Inertia props and the TypeScript type.
 *
 * Distinct from `OperatorSellerData`, which is the *backend* contract handed
 * to Commerce and carries no `name` or `contact_email` because an invoice does
 * not need them. This is the editable surface; that is the read model. Merging
 * them would push console-only fields across a module boundary.
 *
 * `admin_host` is deliberately absent. Changing the host an operator answers
 * on from a page served on that host is a way to lock yourself out of the
 * console, and it belongs with provisioning rather than with settings.
 */
#[TypeScript]
final class OperatorSettingsData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $legalName,
        public readonly ?string $contactEmail,
        public readonly ?string $addressLine,
        public readonly ?string $postalCode,
        public readonly ?string $city,
        public readonly string $countryCode,
        public readonly ?string $vatId,
        public readonly ?string $taxNumber,
        public readonly bool $smallBusinessScheme,
    ) {}

    public static function ofModel(Operator $operator): self
    {
        return new self(
            name: $operator->name,
            legalName: $operator->legal_name,
            contactEmail: $operator->contact_email,
            addressLine: $operator->address_line,
            postalCode: $operator->postal_code,
            city: $operator->city,
            countryCode: $operator->country_code,
            vatId: $operator->vat_id,
            taxNumber: $operator->tax_number,
            smallBusinessScheme: $operator->small_business_scheme,
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Required, and the reason is not tidiness: OperatorSettings
            // refuses to issue an invoice for a brand whose operator has no
            // legal identity, so an empty value here breaks settlement.
            'legalName' => ['required', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'addressLine' => ['nullable', 'string', 'max:255'],
            'postalCode' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:255'],
            // ISO 3166-1 alpha-2. ZUGFeRD expects exactly this, and a
            // free-text country is what makes an invoice fail validation at
            // the recipient rather than here.
            'countryCode' => ['required', 'string', 'size:2', 'alpha'],
            'vatId' => ['nullable', 'string', 'max:32'],
            'taxNumber' => ['nullable', 'string', 'max:32'],
            'smallBusinessScheme' => ['boolean'],
        ];
    }
}
