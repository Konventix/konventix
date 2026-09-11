<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Data;

/**
 * The billing party as it arrived in the settlement request's scalar
 * payload (ADR-0004) — snapshotted onto the invoice at issuance, because
 * the document must stay renderable exactly as issued (ADR-0031) even when
 * the registrant's live data changes later.
 */
final class BillingPartyData extends Data
{
    public function __construct(
        public readonly ?string $organizationName,
        public readonly ?string $recipientName,
        public readonly ?string $email,
        public readonly ?string $addressLine,
        public readonly ?string $postalCode,
        public readonly ?string $city,
        public readonly ?string $countryCode,
        public readonly ?string $vatId,
    ) {}

    /**
     * Deliberately NOT named `from*`: laravel-data treats any static
     * `from*` method as a magic creation method and would route the
     * Eloquent JSON cast through it — with the wrong key shape.
     *
     * @param  array{
     *     organization_name: string|null,
     *     recipient_name: string|null,
     *     email: string|null,
     *     address_line: string|null,
     *     postal_code: string|null,
     *     city: string|null,
     *     country_code: string|null,
     *     vat_id: string|null,
     * }  $payload
     */
    public static function ofEventPayload(array $payload): self
    {
        return new self(
            organizationName: $payload['organization_name'],
            recipientName: $payload['recipient_name'],
            email: $payload['email'],
            addressLine: $payload['address_line'],
            postalCode: $payload['postal_code'],
            city: $payload['city'],
            countryCode: $payload['country_code'],
            vatId: $payload['vat_id'],
        );
    }

    /**
     * A cart checkout's payer (ADR-0047, ADR-0048): the account always
     * supplies the name and the email; a postal address is layered on top
     * only when the payer typed one — which `CartController::checkout()`
     * asks for exactly when the order needs it
     * (`GermanVat::requiresBillingAddress()`, #4). Below that threshold a
     * shop checkout still gathers nothing more than account identity, the
     * small-amount invoice (§33 UStDV) needing no address at all. VAT id
     * stays out of scope here regardless — a cart checkout is B2C by
     * construction, never the reverse-charge path.
     *
     * Country is independent of tax treatment either way: the cart's own
     * tax calculation always resolves ADR-0030's domestic treatment with no
     * billing country passed to it at all (`PlaceOrderFromCart`'s own
     * docblock) — whatever is typed here changes nothing about what tax
     * applies, it is address data for the invoice document alone.
     */
    public static function ofAccount(
        string $name,
        string $email,
        ?string $addressLine = null,
        ?string $postalCode = null,
        ?string $city = null,
        ?string $countryCode = null,
    ): self {
        return new self(
            organizationName: null,
            recipientName: $name,
            email: $email,
            addressLine: $addressLine,
            postalCode: $postalCode,
            city: $city,
            countryCode: $countryCode,
            vatId: null,
        );
    }

    /**
     * The name an invoice addresses: the organization when there is one,
     * else the personal recipient.
     */
    public function displayName(): string
    {
        return $this->organizationName ?? $this->recipientName ?? 'Unbekannter Empfänger';
    }

    /**
     * Whether this party carries enough of a postal address for §14 Abs. 4
     * UStG's full invoice requirements — street, postal code, city and
     * country, the same four fields `SubmitRegistration::guardBillingParty()`
     * requires of an invoice-settled registration (#4: matching that
     * validation is the point, so an operator sees one concept, not two).
     * An `ofAccount()` party with no address supplied answers false, and so
     * does one still missing just the country.
     */
    public function hasPostalAddress(): bool
    {
        return $this->addressLine !== null
            && $this->postalCode !== null
            && $this->city !== null
            && $this->countryCode !== null;
    }
}
