<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Models\Operator;
use Spatie\LaravelData\Data;

/**
 * The operator's legal invoicing identity as scalars (ADR-0001, ADR-0030) —
 * the shape `OperatorSettings` hands to Commerce so an invoice can name its
 * seller without Commerce ever importing the Operator model (ADR-0004's
 * boundary, applied to the shared kernel).
 *
 * Not `#[TypeScript]`: this is a backend contract between modules, not an
 * Inertia prop.
 */
final class OperatorSellerData extends Data
{
    public function __construct(
        public string $operatorId,
        public string $legalName,
        public ?string $addressLine,
        public ?string $postalCode,
        public ?string $city,
        public string $countryCode,
        public ?string $vatId,
        public ?string $taxNumber,
        public bool $smallBusinessScheme,
    ) {}

    public static function fromModel(Operator $operator): self
    {
        return new self(
            operatorId: $operator->getKey(),
            legalName: $operator->legal_name,
            addressLine: $operator->address_line,
            postalCode: $operator->postal_code,
            city: $operator->city,
            countryCode: $operator->country_code,
            vatId: $operator->vat_id,
            taxNumber: $operator->tax_number,
            smallBusinessScheme: $operator->small_business_scheme,
        );
    }
}
