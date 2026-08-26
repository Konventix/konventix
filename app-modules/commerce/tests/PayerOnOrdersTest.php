<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Commerce\Actions\PlaceOrderFromSettlementRequest;
use Konventix\Commerce\Models\Order;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Actions\ApproveRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Models\RegistrationType;

/**
 * ADR-0044: whoever creates a Commerce root names the payer. For an order
 * settling a registration that is Registration, through the settlement
 * request's `registrantUserId`; Commerce stores the ULID and nothing else.
 */
function payerBrand(Brand $brand): Brand
{
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

/**
 * @return array{registrationId: string, brandId: string, settlement: string, billingParty: array<string, string|null>, lines: list<array<string, mixed>>, priceDisplay: string, termsDays: int, requestKey: string}
 */
function payerRequest(Brand $brand): array
{
    $registrationId = (string) Str::ulid();

    return [
        'registrationId' => $registrationId,
        'brandId' => $brand->getKey(),
        'settlement' => 'instant',
        'billingParty' => [
            'organization_name' => null,
            'recipient_name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'address_line' => null,
            'postal_code' => null,
            'city' => null,
            'country_code' => 'DE',
            'vat_id' => null,
        ],
        'lines' => [['label' => 'Ticket', 'quantity' => 1, 'unit_amount_net' => '10.00', 'tax_class' => 'standard']],
        'priceDisplay' => 'gross',
        'termsDays' => 14,
        'requestKey' => $registrationId,
    ];
}

it('stores the payer named by the settlement request on the order', function (): void {
    $brand = payerBrand($this->withBrand());
    $user = User::factory()->create();

    $order = app(PlaceOrderFromSettlementRequest::class)->handle(...payerRequest($brand), payerUserId: $user->getKey());

    expect($order->payer_user_id)->toBe($user->getKey());
});

it('fills a missing payer on the idempotent path so a replayed request converges', function (): void {
    $brand = payerBrand($this->withBrand());
    $user = User::factory()->create();
    $request = payerRequest($brand);

    $first = app(PlaceOrderFromSettlementRequest::class)->handle(...$request);
    expect($first->payer_user_id)->toBeNull();

    $replayed = app(PlaceOrderFromSettlementRequest::class)->handle(...$request, payerUserId: $user->getKey());

    expect($replayed->getKey())->toBe($first->getKey())
        ->and($replayed->refresh()->payer_user_id)->toBe($user->getKey())
        ->and(Order::query()->count())->toBe(1);
});

it('carries the registrant through the whole settlement chain', function (): void {
    payerBrand($this->withBrand());
    $registrant = User::factory()->create();
    $type = RegistrationType::factory()->invoiceSettled()->create();

    $registration = app(SubmitRegistration::class)->handle($type, $registrant->getKey(), new SubmitRegistrationData(
        organizationName: 'Musterfirma GmbH',
        billingRecipientName: 'Erika Musterfrau',
        billingEmail: 'rechnung@musterfirma.example',
        billingAddressLine: 'Musterstraße 1',
        billingPostalCode: '18439',
        billingCity: 'Stralsund',
        billingCountryCode: 'DE',
        billingVatId: null,
    ));
    app(ApproveRegistration::class)->handle($registration, (string) Str::ulid());

    $order = Order::query()->where('settles_id', $registration->getKey())->sole();

    expect($order->payer_user_id)->toBe($registrant->getKey());
});
