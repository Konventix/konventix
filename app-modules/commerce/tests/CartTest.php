<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Commerce\Actions\AddProductToCart;
use Konventix\Commerce\Actions\ChangeCartLineQuantity;
use Konventix\Commerce\Actions\ExpireCarts;
use Konventix\Commerce\Actions\OpenCart;
use Konventix\Commerce\Actions\RemoveCartLine;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Events\CartExpired;
use Konventix\Commerce\Events\CartLineExpired;
use Konventix\Commerce\Events\CartLineRemoved;
use Konventix\Commerce\Events\StockExhausted;
use Konventix\Commerce\Exceptions\StockExhaustedException;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;

/**
 * The cart and its reservation (ADR-0047, Task 9): a person-owned root that
 * locks stock as a quota for `Cart::LIFETIME_MINUTES`, and the sweep that
 * releases it again when nobody checks out.
 */
function cartVariant(int $capacity = 10): ProductVariant
{
    $product = Product::factory()->create();

    return ProductVariant::factory()->for($product, 'product')->withCapacity($capacity)->create();
}

it('consumes stock on add and merges a second add of the same variant into one line', function (): void {
    $this->withBrand();
    $variant = cartVariant(10);
    $cart = Cart::factory()->create();

    $first = app(AddProductToCart::class)->handle($cart, $variant, 3);
    $second = app(AddProductToCart::class)->handle($cart, $variant, 2);

    expect($variant->refresh()->stock_consumed)->toBe(5)
        ->and($second->getKey())->toBe($first->getKey())
        ->and($second->quantity)->toBe(5)
        ->and(CartLine::query()->where('cart_id', $cart->getKey())->count())->toBe(1);
});

it('refuses an add beyond remaining stock and consumes nothing, leaving an earlier add intact', function (): void {
    Event::fake([StockExhausted::class]);
    $this->withBrand();
    $variant = cartVariant(5);
    $cart = Cart::factory()->create();

    app(AddProductToCart::class)->handle($cart, $variant, 4);

    expect(fn () => app(AddProductToCart::class)->handle($cart, $variant, 2))
        ->toThrow(StockExhaustedException::class);

    expect($variant->refresh()->stock_consumed)->toBe(4)
        ->and(CartLine::query()->where('cart_id', $cart->getKey())->sole()->quantity)->toBe(4);
});

it('emits StockExhausted the moment an add exhausts the variant', function (): void {
    Event::fake([StockExhausted::class]);
    $this->withBrand();
    $variant = cartVariant(3);
    $cart = Cart::factory()->create();

    app(AddProductToCart::class)->handle($cart, $variant, 3);

    Event::assertDispatched(StockExhausted::class, fn (StockExhausted $event): bool => $event->variantId === $variant->getKey());
});

it('releases a product line\'s stock on removal', function (): void {
    Event::fake([CartLineRemoved::class]);
    $this->withBrand();
    $variant = cartVariant(10);
    $cart = Cart::factory()->create();
    $line = app(AddProductToCart::class)->handle($cart, $variant, 4);

    app(RemoveCartLine::class)->handle($line);

    expect($variant->refresh()->stock_consumed)->toBe(0)
        ->and(CartLine::query()->whereKey($line->getKey())->exists())->toBeFalse();
    Event::assertNotDispatched(CartLineRemoved::class);
});

it('emits CartLineRemoved with its requestKey when a registration line is removed', function (): void {
    Event::fake([CartLineRemoved::class]);
    $brand = $this->withBrand();
    $cart = Cart::factory()->create();
    // request_key is a ulid() column — a fixed-width char(26) in Postgres —
    // so a real ULID-shaped value is used rather than a short literal,
    // which Postgres would space-pad and fail the equality check below.
    $requestKey = (string) Str::ulid();
    $line = CartLine::factory()->forCart($cart)->registration($requestKey)->create();

    app(RemoveCartLine::class)->handle($line);

    expect(CartLine::query()->whereKey($line->getKey())->exists())->toBeFalse();
    Event::assertDispatched(CartLineRemoved::class, fn (CartLineRemoved $event): bool => $event->cartId === $cart->getKey()
        && $event->brandId === $brand->getKey()
        && $event->kind === CartLineKind::Registration->value
        && $event->refId === $line->ref_id
        && $event->requestKey === $requestKey);
});

it('consumes the delta on a quantity increase and releases it on a decrease', function (): void {
    $this->withBrand();
    $variant = cartVariant(10);
    $cart = Cart::factory()->create();
    $line = app(AddProductToCart::class)->handle($cart, $variant, 3);

    app(ChangeCartLineQuantity::class)->handle($line, 6);
    expect($variant->refresh()->stock_consumed)->toBe(6);

    app(ChangeCartLineQuantity::class)->handle($line->refresh(), 2);
    expect($variant->refresh()->stock_consumed)->toBe(2)
        ->and($line->refresh()->quantity)->toBe(2);
});

it('refuses a quantity increase beyond remaining stock', function (): void {
    $this->withBrand();
    $variant = cartVariant(5);
    $cart = Cart::factory()->create();
    $line = app(AddProductToCart::class)->handle($cart, $variant, 3);

    expect(fn () => app(ChangeCartLineQuantity::class)->handle($line, 10))
        ->toThrow(StockExhaustedException::class);

    expect($variant->refresh()->stock_consumed)->toBe(3);
});

it('refuses a quantity change on a registration line', function (): void {
    $this->withBrand();
    $cart = Cart::factory()->create();
    $line = CartLine::factory()->forCart($cart)->registration()->create();

    expect(fn () => app(ChangeCartLineQuantity::class)->handle($line, 2))
        ->toThrow(LogicException::class);
});

it('returns the same cart on a second OpenCart::for() call', function (): void {
    $brand = $this->withBrand();
    $payerUserId = (string) Str::ulid();

    $first = OpenCart::for($brand->getKey(), $payerUserId);
    $second = OpenCart::for($brand->getKey(), $payerUserId);

    expect($second->getKey())->toBe($first->getKey())
        ->and(Cart::withoutBrandScope()->where('brand_id', $brand->getKey())->where('payer_user_id', $payerUserId)->count())->toBe(1);
});

it('moves expires_at forward on every change to the cart', function (): void {
    $this->withBrand();
    $variant = cartVariant(10);
    $cart = Cart::factory()->create();
    $originalExpiry = $cart->expires_at;

    $this->travel(1)->minutes();
    $line = app(AddProductToCart::class)->handle($cart, $variant, 2);
    expect($cart->refresh()->expires_at->gt($originalExpiry))->toBeTrue();
    $afterAdd = $cart->expires_at;

    $this->travel(1)->minutes();
    app(ChangeCartLineQuantity::class)->handle($line->refresh(), 4);
    expect($cart->refresh()->expires_at->gt($afterAdd))->toBeTrue();
    $afterChange = $cart->expires_at;

    $this->travel(1)->minutes();
    app(RemoveCartLine::class)->handle($line->refresh());
    expect($cart->refresh()->expires_at->gt($afterChange))->toBeTrue();
});

it('sweeps expired carts: releases product stock, emits CartLineExpired for a registration line, and leaves a fresh cart alone', function (): void {
    Event::fake([CartLineExpired::class, CartExpired::class]);
    $brand = $this->withBrand();
    $variant = cartVariant(10);

    // request_key is a ulid() column — a fixed-width char(26) in Postgres —
    // so a real ULID-shaped value is used rather than a short literal,
    // which Postgres would space-pad and fail the equality check below.
    $requestKey = (string) Str::ulid();

    $expiring = Cart::factory()->create();
    app(AddProductToCart::class)->handle($expiring, $variant, 4);
    $registrationLine = CartLine::factory()->forCart($expiring)->registration($requestKey)->create();
    // Adding touched expiry back into the future — push it into the past
    // again, after every write, so the sweep actually picks this cart up.
    $expiring->forceFill(['expires_at' => now()->subMinute()])->save();

    $fresh = Cart::factory()->create();

    $expiredCount = app(ExpireCarts::class)->handle();

    expect($expiredCount)->toBe(1)
        ->and($variant->refresh()->stock_consumed)->toBe(0)
        ->and($expiring->refresh()->status)->toBe(CartStatus::Expired)
        ->and($fresh->refresh()->status)->toBe(CartStatus::Open);

    Event::assertDispatched(CartLineExpired::class, fn (CartLineExpired $event): bool => $event->cartId === $expiring->getKey()
        && $event->kind === CartLineKind::Registration->value
        && $event->refId === $registrationLine->ref_id
        && $event->requestKey === $requestKey);

    Event::assertDispatched(CartExpired::class, fn (CartExpired $event): bool => $event->cartId === $expiring->getKey()
        && $event->brandId === $brand->getKey()
        && $event->payerUserId === $expiring->payer_user_id);
});

it('runs the sweep through artisan commerce:expire-carts', function (): void {
    $this->withBrand();
    $cart = Cart::factory()->pastDue()->create();

    $this->artisan('commerce:expire-carts')
        ->expectsOutputToContain('Expired 1 cart(s).')
        ->assertSuccessful();

    expect($cart->refresh()->status)->toBe(CartStatus::Expired);
});
