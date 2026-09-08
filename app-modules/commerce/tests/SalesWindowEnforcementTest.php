<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Konventix\Commerce\Actions\OpenCart;
use Konventix\Commerce\Actions\PlaceOrderFromCart;
use Konventix\Commerce\Actions\PlaceRegistrationLineInCart;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Exceptions\CartCheckoutRefusedException;
use Konventix\Commerce\Exceptions\SaleWindowClosedException;
use Konventix\Commerce\Models\CartLine;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * #218 (#216 draft i): a registration-kind cart line carries its own sales
 * window, and both Actions that create money out of it refuse a closed one.
 *
 * Commerce never reads Registration's table (ADR-0004): the window arrives as
 * two nullable ISO-8601 scalars on `RegistrationSettlementRequested` and is
 * snapshotted onto the cart line, exactly as `label`, `unit_amount_net` and
 * `tax_class` already are. That snapshot is what makes the checkout-time
 * check possible at all — and it is the gap #216 records for
 * `Product::published_at`, which is read when the line is created and never
 * again.
 *
 * Helper names prefixed `saleWindow*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function saleWindowBrand(): Brand
{
    $brand = test()->withBrand();
    $brand->operator()->associate(Operator::factory()->create())->save();

    return $brand;
}

/**
 * @return list<array{label: string, quantity: int, unit_amount_net: string, tax_class: string}>
 */
function saleWindowLines(): array
{
    return [['label' => 'Weekend Ticket', 'quantity' => 1, 'unit_amount_net' => '50.00', 'tax_class' => 'standard']];
}

it('places a registration line and snapshots the sales window onto it', function (): void {
    $brand = saleWindowBrand();
    $registrantUserId = (string) Str::ulid();
    $registrationId = (string) Str::ulid();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    $line = app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: $registrantUserId,
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: saleWindowLines(),
        saleStartsAt: '2026-09-01T18:00:00+00:00',
        saleEndsAt: '2026-10-01T18:00:00+00:00',
    );

    expect($line->kind)->toBe(CartLineKind::Registration)
        ->and($line->sale_starts_at?->toIso8601String())->toBe('2026-09-01T18:00:00+00:00')
        ->and($line->sale_ends_at?->toIso8601String())->toBe('2026-10-01T18:00:00+00:00');
});

it('refuses a registration line whose sale has not started', function (): void {
    $brand = saleWindowBrand();
    $registrationId = (string) Str::ulid();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    expect(fn () => app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: (string) Str::ulid(),
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: saleWindowLines(),
        saleStartsAt: '2026-10-01T18:00:00+00:00',
    ))->toThrow(SaleWindowClosedException::class);

    expect(CartLine::withoutBrandScope()->where('ref_id', $registrationId)->exists())->toBeFalse();
});

it('refuses a registration line whose sale has ended', function (): void {
    $brand = saleWindowBrand();
    $registrationId = (string) Str::ulid();

    $this->travelTo(Carbon::parse('2026-09-08 12:00:00Z'));

    expect(fn () => app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: (string) Str::ulid(),
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: saleWindowLines(),
        saleEndsAt: '2026-09-01T18:00:00+00:00',
    ))->toThrow(SaleWindowClosedException::class);

    expect(CartLine::withoutBrandScope()->where('ref_id', $registrationId)->exists())->toBeFalse();
});

it('places a registration line that declares no window at all', function (): void {
    $brand = saleWindowBrand();
    $registrationId = (string) Str::ulid();

    $line = app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: (string) Str::ulid(),
        registrationId: $registrationId,
        requestKey: $registrationId,
        lines: saleWindowLines(),
    );

    expect($line->sale_starts_at)->toBeNull()
        ->and($line->sale_ends_at)->toBeNull();
});

it('refuses a checkout whose registration line left its window while the cart sat open', function (): void {
    $brand = saleWindowBrand();
    $user = User::factory()->create();
    $account = cartCheckoutAccount();

    $cart = OpenCart::for($brand->getKey(), $user->getKey());
    CartLine::factory()->forCart($cart)->registration()->create([
        'label' => 'Weekend Ticket',
        'unit_amount_net' => '50.00',
        'sale_ends_at' => '2026-09-08 18:00:00',
    ]);

    $this->travelTo(Carbon::parse('2026-09-08 19:00:00Z'));

    expect(fn () => app(PlaceOrderFromCart::class)->handle(
        $cart->fresh(),
        $account,
        'https://brand.test/cart',
        cartCheckoutBillingParty($user),
    ))->toThrow(CartCheckoutRefusedException::class);

    expect($cart->fresh()->checked_out_order_id)->toBeNull();
});

it('checks out a registration line that is still inside its window', function (): void {
    $brand = saleWindowBrand();
    $user = User::factory()->create();
    $account = cartCheckoutAccount();

    $cart = OpenCart::for($brand->getKey(), $user->getKey());
    CartLine::factory()->forCart($cart)->registration()->create([
        'label' => 'Weekend Ticket',
        'unit_amount_net' => '50.00',
        'sale_starts_at' => '2026-09-01 18:00:00',
        'sale_ends_at' => '2026-10-01 18:00:00',
    ]);

    $this->travelTo(Carbon::parse('2026-09-08 19:00:00Z'));

    $redirect = app(PlaceOrderFromCart::class)->handle(
        $cart->fresh(),
        $account,
        'https://brand.test/cart',
        cartCheckoutBillingParty($user),
    );

    expect($redirect->url)->not->toBeEmpty()
        ->and($cart->fresh()->checked_out_order_id)->not->toBeNull();
});
