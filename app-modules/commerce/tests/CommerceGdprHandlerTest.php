<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Commerce\Actions\AddProductToCart;
use Konventix\Commerce\Actions\OpenCart;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Events\CartLineRemoved;
use Konventix\Commerce\Gdpr\CommerceGdprHandler;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;

/**
 * Commerce's contribution to a subject request (ADR-0044): export by the
 * payer column, erase nothing — an issued invoice is retained under §147 AO.
 */
it('is registered with Compliance under its module name', function (): void {
    expect(app(GdprHandlerRegistry::class)->has('commerce'))->toBeTrue()
        ->and(app(GdprHandlerRegistry::class)->get('commerce'))->toBeInstanceOf(CommerceGdprHandler::class);
});

it('exports the subject\'s orders and invoices on that brand only', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $mine = Order::factory()->forPayer($user->getKey())->paid()->create();
    Invoice::factory()->create(['order_id' => $mine->getKey(), 'invoice_number' => 'KVX-2026-00007']);
    Order::factory()->forPayer(User::factory()->create()->getKey())->create();

    $elsewhere = Brand::factory()->create();
    $this->withBrand($elsewhere);
    Order::factory()->forPayer($user->getKey())->create();
    $this->withBrand($brand);

    $export = (new CommerceGdprHandler)->exportFor($user->getKey(), $brand->getKey());

    expect($export['orders'])->toHaveCount(1)
        ->and($export['orders'][0]['id'])->toBe($mine->getKey())
        ->and($export['orders'][0]['invoices'][0]['invoice_number'])->toBe('KVX-2026-00007')
        ->and($export['orders'][0]['invoices'][0]['billing_party'])->toBeArray();
});

it('erases nothing in either deletion mode and keeps the payer link', function (DeletionMode $mode): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    $order = Order::factory()->forPayer($user->getKey())->paid()->create();
    Invoice::factory()->create(['order_id' => $order->getKey()]);

    (new CommerceGdprHandler)->delete($user->getKey(), $brand->getKey(), $mode);

    expect(Order::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and($order->refresh()->payer_user_id)->toBe($user->getKey());
})->with([DeletionMode::Anonymize, DeletionMode::Erase]);

it('exports the subject\'s carts on that brand only (ADR-0047)', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $variant = ProductVariant::factory()->for(Product::factory(), 'product')->withCapacity(10)->create();
    $cart = OpenCart::for($brand->getKey(), $user->getKey());
    app(AddProductToCart::class)->handle($cart, $variant, 2);

    // Another payer's cart, and this payer's cart on a different brand,
    // must not appear.
    OpenCart::for($brand->getKey(), (string) Str::ulid());
    $elsewhere = Brand::factory()->create();
    $this->withBrand($elsewhere);
    OpenCart::for($elsewhere->getKey(), $user->getKey());
    $this->withBrand($brand);

    $export = (new CommerceGdprHandler)->exportFor($user->getKey(), $brand->getKey());

    expect($export['carts'])->toHaveCount(1)
        ->and($export['carts'][0]['id'])->toBe($cart->id)
        ->and($export['carts'][0]['status'])->toBe('open')
        ->and($export['carts'][0]['lines'])->toHaveCount(1)
        ->and($export['carts'][0]['lines'][0]['kind'])->toBe('product');
});

it('deletes an open cart on erasure, releasing a product line\'s stock', function (DeletionMode $mode): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $variant = ProductVariant::factory()->for(Product::factory(), 'product')->withCapacity(10)->create();
    $cart = OpenCart::for($brand->getKey(), $user->getKey());
    app(AddProductToCart::class)->handle($cart, $variant, 3);
    expect($variant->fresh()->stock_consumed)->toBe(3);

    (new CommerceGdprHandler)->delete($user->getKey(), $brand->getKey(), $mode);

    expect(Cart::query()->whereKey($cart->getKey())->exists())->toBeFalse()
        ->and($variant->fresh()->stock_consumed)->toBe(0);
})->with([DeletionMode::Anonymize, DeletionMode::Erase]);

it('emits CartLineRemoved for a registration line on erasure, so Registration lets go of it', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $cart = OpenCart::for($brand->getKey(), $user->getKey());
    $requestKey = (string) Str::ulid();
    CartLine::factory()->forCart($cart)->registration($requestKey)->create();

    Event::fake([CartLineRemoved::class]);

    (new CommerceGdprHandler)->delete($user->getKey(), $brand->getKey(), DeletionMode::Erase);

    Event::assertDispatched(CartLineRemoved::class, fn (CartLineRemoved $event): bool => $event->cartId === $cart->getKey()
        && $event->requestKey === $requestKey);
});

it('leaves a checked-out or expired cart alone on erasure', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $checkedOut = Cart::factory()->forPayer($user->getKey())->create([
        'status' => CartStatus::CheckedOut,
        'checked_out_order_id' => (string) Str::ulid(),
    ]);
    $expired = Cart::factory()->forPayer($user->getKey())->expired()->create();

    (new CommerceGdprHandler)->delete($user->getKey(), $brand->getKey(), DeletionMode::Erase);

    expect(Cart::query()->whereKey($checkedOut->getKey())->exists())->toBeTrue()
        ->and(Cart::query()->whereKey($expired->getKey())->exists())->toBeTrue();
});
