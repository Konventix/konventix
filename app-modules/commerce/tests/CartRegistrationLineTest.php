<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Events\InvoiceIssued;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Order;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Events\RegistrationSettlementRequested;

/**
 * ADR-0047 point 3 (Task 10): a registration joins the cart by submitting
 * first. With the brand's `commerce:shop` feature on, an `instant`
 * settlement request lands in the registrant's open cart as a line instead
 * of placing an order; `invoice` settlements, and every settlement on a
 * brand with the feature off, still get the direct order Task 9's listener
 * always produced.
 */
function cartRegOperatorBrand(Brand $brand): Brand
{
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

/**
 * The real payload shape `RequestSettlement` builds, copied rather than
 * called through the Action so this test exercises the listener's own
 * branching from the event itself.
 *
 * @return array{registrationId: string, registrationTypeId: string, eventId: string, brandId: string, registrantUserId: string, settlement: string, billingParty: array<string, string|null>, lines: list<array{label: string, quantity: int, unit_amount_net: string, tax_class: string}>, priceDisplay: string, termsDays: int, requestedAt: string, requestKey: string}
 */
function cartRegPayload(Brand $brand, string $registrantUserId, string $settlement = 'instant', ?string $requestKey = null): array
{
    $registrationId = (string) Str::ulid();

    return [
        'registrationId' => $registrationId,
        'registrationTypeId' => (string) Str::ulid(),
        'eventId' => (string) Str::ulid(),
        'brandId' => $brand->getKey(),
        'registrantUserId' => $registrantUserId,
        'settlement' => $settlement,
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
        'lines' => [
            ['label' => 'Ticket', 'quantity' => 1, 'unit_amount_net' => '10.00', 'tax_class' => 'standard'],
            ['label' => 'Add-on', 'quantity' => 2, 'unit_amount_net' => '5.00', 'tax_class' => 'reduced'],
        ],
        'priceDisplay' => 'gross',
        'termsDays' => 14,
        'requestedAt' => now()->toIso8601String(),
        'requestKey' => $requestKey ?? $registrationId,
    ];
}

/**
 * @param  array{registrationId: string, registrationTypeId: string, eventId: string, brandId: string, registrantUserId: string, settlement: string, billingParty: array<string, string|null>, lines: list<array{label: string, quantity: int, unit_amount_net: string, tax_class: string}>, priceDisplay: string, termsDays: int, requestedAt: string, requestKey: string}  $payload
 */
function cartRegDispatch(array $payload): void
{
    event(new RegistrationSettlementRequested(...$payload));
}

it('creates a cart line and no order for an instant settlement request when the shop feature is on', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $registrantUserId = (string) Str::ulid();
    $payload = cartRegPayload($brand, $registrantUserId);

    cartRegDispatch($payload);

    $cart = Cart::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->where('payer_user_id', $registrantUserId)
        ->sole();
    $line = CartLine::query()->where('cart_id', $cart->getKey())->sole();

    expect($line->kind)->toBe(CartLineKind::Registration)
        ->and($line->ref_id)->toBe($payload['registrationId'])
        ->and($line->request_key)->toBe($payload['requestKey'])
        ->and($line->quantity)->toBe(1)
        ->and(Order::query()->count())->toBe(0);
});

it('converges to one cart line when the same settlement request replays', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $registrantUserId = (string) Str::ulid();
    $payload = cartRegPayload($brand, $registrantUserId);

    cartRegDispatch($payload);
    cartRegDispatch($payload);

    expect(CartLine::query()->where('kind', CartLineKind::Registration)->count())->toBe(1)
        ->and(Order::query()->count())->toBe(0);
});

it('still creates an order for an invoice settlement even with the shop feature on', function (): void {
    $brand = cartRegOperatorBrand($this->withBrand());
    $this->activateFeature('commerce:shop', $brand);
    Event::fake([OrderPlaced::class, InvoiceIssued::class]);
    Queue::fake();
    $registrantUserId = (string) Str::ulid();
    $payload = cartRegPayload($brand, $registrantUserId, settlement: 'invoice');

    cartRegDispatch($payload);

    expect(Order::query()->where('settles_id', $payload['registrationId'])->exists())->toBeTrue()
        ->and(CartLine::query()->count())->toBe(0);
});

it('creates an order for an instant settlement request when the shop feature is off', function (): void {
    $brand = cartRegOperatorBrand($this->withBrand());
    Event::fake([OrderPlaced::class]);
    $registrantUserId = (string) Str::ulid();
    $payload = cartRegPayload($brand, $registrantUserId);

    cartRegDispatch($payload);

    expect(Order::query()->where('settles_id', $payload['registrationId'])->exists())->toBeTrue()
        ->and(CartLine::query()->count())->toBe(0);
});

it("sets a registration line's unit_amount_net to the request's total net", function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);
    $registrantUserId = (string) Str::ulid();
    // Ticket 1 x 10.00 + Add-on 2 x 5.00 = 20.00 total net.
    $payload = cartRegPayload($brand, $registrantUserId);

    cartRegDispatch($payload);

    $line = CartLine::query()->where('kind', CartLineKind::Registration)->sole();

    expect($line->unit_amount_net)->toBe('20.00')
        ->and($line->label)->toBe('Ticket')
        ->and($line->tax_class->value)->toBe('standard');
});
