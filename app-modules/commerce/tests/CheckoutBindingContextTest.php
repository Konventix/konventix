<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Commerce\Actions\PlaceOrderFromCart;
use Konventix\Commerce\Actions\PlaceRegistrationLineInCart;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\CheckoutConsentSlots;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Events\RegistrationSettlementRequested;

/**
 * The opaque bag (ADR-0066 §7.2, D-CPL-06).
 *
 * Commerce has to hand Compliance a context containing registration types
 * and entry conditions, and Commerce may not read Registration's tables
 * (ADR-0004). So the settlement request carries the scalars, Commerce stores
 * them on the line it produced and **interprets no key of them**, and hands
 * the union of its lines' bags to the consent slot.
 *
 * What this file pins is the survival of the bag end to end, and that
 * nothing in this module reads a meaning out of it: no query filters on it,
 * no branch depends on a key, and a line placed before the column existed
 * carries null and resolves exactly as it always did.
 *
 * Helper names prefixed `bindingBag*`: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function bindingBagSettlementRequest(string $brandId, string $registrationId, array $bag): void
{
    event(new RegistrationSettlementRequested(
        registrationId: $registrationId,
        registrationTypeId: $bag['registration_type_ids'][0] ?? (string) Str::ulid(),
        eventId: $bag['event_id'] ?? (string) Str::ulid(),
        brandId: $brandId,
        registrantUserId: (string) Str::ulid(),
        settlement: 'invoice',
        billingParty: [
            'organization_name' => null,
            'recipient_name' => 'Payer',
            'email' => 'payer@example.test',
            'address_line' => null,
            'postal_code' => null,
            'city' => null,
            'country_code' => null,
            'vat_id' => null,
        ],
        lines: [['label' => 'Weekend ticket', 'quantity' => 1, 'unit_amount_net' => '100.00', 'tax_class' => 'standard']],
        priceDisplay: 'gross',
        termsDays: 14,
        requestedAt: now()->toIso8601String(),
        requestKey: $registrationId,
        bindingContext: $bag,
    ));
}

it('carries the settlement request bag onto the order line, uninterpreted', function (): void {
    $brand = $this->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();

    $registrationId = (string) Str::ulid();
    $typeId = (string) Str::ulid();
    $eventId = (string) Str::ulid();
    $conditionId = (string) Str::ulid();

    bindingBagSettlementRequest($brand->getKey(), $registrationId, [
        'event_id' => $eventId,
        'registration_type_ids' => [$typeId],
        'entry_condition_ids' => [$conditionId],
        'registration_id' => $registrationId,
    ]);

    $line = OrderLine::withoutBrandScope()->sole();

    expect($line->binding_context)->toBe([
        'event_id' => $eventId,
        'registration_type_ids' => [$typeId],
        'entry_condition_ids' => [$conditionId],
        'registration_id' => $registrationId,
    ]);
});

it('carries the bag through the cart to the order line when the shop is on', function (): void {
    // ADR-0047 point 3: an `instant` settlement becomes a cart line first. A
    // bag that stopped at the cart would leave the whole shop path
    // resolving unbound policies only.
    $brand = $this->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();

    $payer = User::factory()->create();
    $registrationId = (string) Str::ulid();
    $typeId = (string) Str::ulid();

    $cartLine = app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: (string) $payer->getKey(),
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: [['label' => 'Weekend ticket', 'quantity' => 1, 'unit_amount_net' => '100.00', 'tax_class' => 'standard']],
        bindingContext: ['event_id' => 'E1', 'registration_type_ids' => [$typeId]],
    );

    expect($cartLine->binding_context)->toBe(['event_id' => 'E1', 'registration_type_ids' => [$typeId]]);

    $account = SettlementAccount::factory()->create(['provider' => SettlementAccount::PROVIDER_NULL]);

    app(PlaceOrderFromCart::class)->handle(
        $cartLine->cart,
        $account,
        fn (Order $order): string => 'https://example.test/orders/'.$order->getKey(),
        BillingPartyData::ofAccount('Payer', 'payer@example.test', null, null, null, null),
    );

    expect(OrderLine::withoutBrandScope()->sole()->binding_context)
        ->toBe(['event_id' => 'E1', 'registration_type_ids' => [$typeId]]);
});

it('leaves a line with no bag at null — there was no backfill', function (): void {
    $this->withBrand();

    $order = Order::factory()->create();
    $line = OrderLine::factory()->forOrder($order)->create();

    expect($line->binding_context)->toBeNull()
        // And the union of nothing is nothing, which resolves the unbound
        // policies only — exactly today's behaviour.
        ->and(CheckoutConsentSlots::unionOfBags([null]))->toBe([]);
});

it('unions the bags of several lines without reading what any key means', function (): void {
    $union = CheckoutConsentSlots::unionOfBags([
        ['event_id' => 'E1', 'registration_type_ids' => ['T1'], 'entry_condition_ids' => ['C1']],
        null,
        // A second registration in one cart: the types are alternatives, so
        // both travel (OR within a kind, T-10 — over-asking, never under).
        ['event_id' => 'E2', 'registration_type_ids' => ['T2', 'T1'], 'registration_id' => 'R2'],
    ]);

    expect($union['registration_type_ids'])->toBe(['T1', 'T2'])
        ->and($union['entry_condition_ids'])->toBe(['C1'])
        // Single-valued in the resolver's context, so the first bag that
        // names one wins — a documented narrowing for a multi-event cart.
        ->and($union['event_id'])->toBe('E1')
        ->and($union['registration_id'])->toBe('R2');
});

it('never queries or branches on the bag anywhere in this module', function (): void {
    // The architectural half of D-CPL-06: Commerce stores the bag and hands
    // it on. A `where('binding_context', …)`, a `->binding_context['…']` read
    // or a match on one of its keys would mean this module had started to
    // understand it — which is exactly what the boundary forbids.
    $sources = [];
    $directory = new RecursiveDirectoryIterator(dirname(__DIR__).'/src');

    foreach (new RecursiveIteratorIterator($directory) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    $offenders = [];

    foreach ($sources as $path => $contents) {
        foreach (["binding_context'", 'binding_context['] as $needle) {
            if (! str_contains($contents, $needle)) {
                continue;
            }

            // The three places that may name the column at all: the two
            // models' schema declarations and the Actions that copy it.
            if (preg_match('/(Models|Actions)\//', $path) === 1) {
                continue;
            }

            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([]);
});
