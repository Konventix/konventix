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
     * A cart checkout's payer (ADR-0047, ADR-0048): the account holds only a
     * name and an email, never a postal address or a VAT id — so a shop
     * order's invoice addresses the payer by account identity alone. Country
     * is deliberately null rather than a guessed `'DE'`: the cart's own tax
     * calculation already resolves ADR-0030's domestic treatment with no
     * billing country at all (`PlaceOrderFromCart`'s own docblock), so a
     * null here changes nothing about what tax applies — it would only be a
     * fabricated fact on the invoice.
     */
    public static function ofAccount(string $name, string $email): self
    {
        return new self(
            organizationName: null,
            recipientName: $name,
            email: $email,
            addressLine: null,
            postalCode: null,
            city: null,
            countryCode: null,
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
}
