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
use Konventix\Compliance\Gdpr\SubjectRef;
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

it('exports the subject\'s orders and invoices across every brand of the operator', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $mine = Order::factory()->forPayer($user->getKey())->paid()->create();
    Invoice::factory()->create(['order_id' => $mine->getKey(), 'invoice_number' => 'KVX-2026-00007']);
    Order::factory()->forPayer(User::factory()->create()->getKey())->create();

    // A second brand under the same operator, ADR-0050: the request spans
    // the operator, so this order must appear in the same archive rather
    // than being invisible to a handler that used to be handed one brand.
    $elsewhere = Brand::factory()->create();
    $this->withBrand($elsewhere);
    $alsoMine = Order::factory()->forPayer($user->getKey())->create();
    $this->withBrand($brand);

    $export = (new CommerceGdprHandler)->exportFor(SubjectRef::forUser($user->getKey()));

    expect($export['orders'])->toHaveCount(2)
        ->and(collect($export['orders'])->pluck('id')->all())->toEqualCanonicalizing([$mine->getKey(), $alsoMine->getKey()]);

    $mineExport = collect($export['orders'])->firstWhere('id', $mine->getKey());

    expect($mineExport['invoices'][0]['invoice_number'])->toBe('KVX-2026-00007')
        ->and($mineExport['invoices'][0]['billing_party'])->toBeArray();
});

it('erases nothing in either deletion mode and keeps the payer link', function (DeletionMode $mode): void {
    $this->withBrand();
    $user = User::factory()->create();
    $order = Order::factory()->forPayer($user->getKey())->paid()->create();
    Invoice::factory()->create(['order_id' => $order->getKey()]);

    (new CommerceGdprHandler)->delete(SubjectRef::forUser($user->getKey()), $mode);

    expect(Order::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and($order->refresh()->payer_user_id)->toBe($user->getKey());
})->with([DeletionMode::Anonymize, DeletionMode::Erase]);

it('exports the subject\'s carts across every brand of the operator (ADR-0047, ADR-0050)', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $variant = ProductVariant::factory()->for(Product::factory(), 'product')->withCapacity(10)->create();
    $cart = OpenCart::for($brand->getKey(), $user->getKey());
    app(AddProductToCart::class)->handle($cart, $variant, 2);

    // Another payer's cart must not appear, but this payer's cart on a
    // different brand of the same operator now does — the request spans
    // the operator (ADR-0050).
    OpenCart::for($brand->getKey(), (string) Str::ulid());
    $elsewhere = Brand::factory()->create();
    $this->withBrand($elsewhere);
    $alsoMine = OpenCart::for($elsewhere->getKey(), $user->getKey());
    $this->withBrand($brand);

    $export = (new CommerceGdprHandler)->exportFor(SubjectRef::forUser($user->getKey()));

    expect($export['carts'])->toHaveCount(2)
        ->and(collect($export['carts'])->pluck('id')->all())->toEqualCanonicalizing([$cart->id, $alsoMine->id]);

    $mineExport = collect($export['carts'])->firstWhere('id', $cart->id);

    expect($mineExport['status'])->toBe('open')
        ->and($mineExport['lines'])->toHaveCount(1)
        ->and($mineExport['lines'][0]['kind'])->toBe('product');
});

it('deletes an open cart on erasure, releasing a product line\'s stock', function (DeletionMode $mode): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $variant = ProductVariant::factory()->for(Product::factory(), 'product')->withCapacity(10)->create();
    $cart = OpenCart::for($brand->getKey(), $user->getKey());
    app(AddProductToCart::class)->handle($cart, $variant, 3);
    expect($variant->fresh()->stock_consumed)->toBe(3);

    (new CommerceGdprHandler)->delete(SubjectRef::forUser($user->getKey()), $mode);

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

    (new CommerceGdprHandler)->delete(SubjectRef::forUser($user->getKey()), DeletionMode::Erase);

    Event::assertDispatched(CartLineRemoved::class, fn (CartLineRemoved $event): bool => $event->cartId === $cart->getKey()
        && $event->requestKey === $requestKey);
});

it('leaves a checked-out or expired cart alone on erasure', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $checkedOut = Cart::factory()->forPayer($user->getKey())->create([
        'status' => CartStatus::CheckedOut,
        'checked_out_order_id' => (string) Str::ulid(),
    ]);
    $expired = Cart::factory()->forPayer($user->getKey())->expired()->create();

    (new CommerceGdprHandler)->delete(SubjectRef::forUser($user->getKey()), DeletionMode::Erase);

    expect(Cart::query()->whereKey($checkedOut->getKey())->exists())->toBeTrue()
        ->and(Cart::query()->whereKey($expired->getKey())->exists())->toBeTrue();
});
