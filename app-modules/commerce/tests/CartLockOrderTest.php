<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Commerce\Actions\AddProductToCart;
use Konventix\Commerce\Actions\ChangeCartLineQuantity;
use Konventix\Commerce\Actions\ExpireCarts;
use Konventix\Commerce\Actions\PlaceRegistrationLineInCart;
use Konventix\Commerce\Actions\RemoveCartLine;
use Konventix\Commerce\Events\CartExpired;
use Konventix\Commerce\Events\CartLineExpired;
use Konventix\Commerce\Events\CartLineRemoved;
use Konventix\Commerce\Events\RegistrationLinePlacedInCart;
use Konventix\Commerce\Events\StockExhausted;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;

/**
 * The lock order every cart write takes (#233).
 *
 * A cart write used to reach `cart_lines` first and the parent `carts` row
 * only through `Cart::touchExpiry()` at the end. On Postgres the foreign key
 * makes the child write take `FOR KEY SHARE` on the parent and `touchExpiry()`
 * then asks for `FOR NO KEY UPDATE` on the same row, so two concurrent
 * transactions on one cart each held a share the other's escalation had to
 * wait for — SQLSTATE 40P01, one of them killed. It reproduced in
 * `SalesWindowTest` during the night of 2026-09-08.
 *
 * **Why these tests read the query log rather than racing two connections.**
 * A real race needs two committed-visible connections, and this suite runs
 * every test inside RefreshDatabase's transaction: a second connection sees
 * none of the fixture rows, and a test that leaves the transaction
 * (`.ai/rules/tests.md`, `InvoiceNumberingProbeTest`) would have to spawn a
 * second process, commit its fixtures, and then *rely on hitting* a timing
 * window to fail — a test that proves nothing when it passes and flakes when
 * the machine is busy. The order itself is the whole fix, it is a property of
 * a single transaction, and the query log states it exactly: the `carts` row
 * is locked before the first byte is written to `cart_lines`, and the
 * variant is locked before the cart. That is the assertion that would have
 * caught this bug at the time the four Actions were written.
 */

/**
 * Every statement one closure executed, in order.
 *
 * @return list<string>
 */
function cartLockQueryLog(Closure $work): array
{
    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    $work();

    return $statements;
}

/**
 * The position of the first `SELECT … FROM "carts" … FOR UPDATE`, or null.
 *
 * @param  list<string>  $statements
 */
function cartLockPosition(array $statements): ?int
{
    foreach ($statements as $index => $sql) {
        if (str_starts_with($sql, 'select') && str_contains($sql, 'from "carts"') && str_contains($sql, 'for update')) {
            return $index;
        }
    }

    return null;
}

/**
 * The position of the first write to `cart_lines`, or null.
 *
 * @param  list<string>  $statements
 */
function cartLineWritePosition(array $statements): ?int
{
    foreach ($statements as $index => $sql) {
        foreach (['insert into "cart_lines"', 'update "cart_lines"', 'delete from "cart_lines"'] as $write) {
            if (str_starts_with($sql, $write)) {
                return $index;
            }
        }
    }

    return null;
}

/**
 * The position of the first `SELECT … FROM "product_variants" … FOR UPDATE`.
 *
 * @param  list<string>  $statements
 */
function variantLockPosition(array $statements): ?int
{
    foreach ($statements as $index => $sql) {
        if (str_starts_with($sql, 'select') && str_contains($sql, 'from "product_variants"') && str_contains($sql, 'for update')) {
            return $index;
        }
    }

    return null;
}

function cartLockVariant(int $capacity = 10): ProductVariant
{
    $product = Product::factory()->create();

    return ProductVariant::factory()->for($product, 'product')->withCapacity($capacity)->create();
}

it('locks the cart row before the first cart_lines write when a product is added', function (): void {
    Event::fake([StockExhausted::class]);
    $this->withBrand();
    $variant = cartLockVariant(10);
    $cart = Cart::factory()->create();

    $statements = cartLockQueryLog(fn () => app(AddProductToCart::class)->handle($cart, $variant, 2));

    $lock = cartLockPosition($statements);
    $write = cartLineWritePosition($statements);

    expect($lock)->not->toBeNull()
        ->and($write)->not->toBeNull()
        ->and($lock)->toBeLessThan($write)
        // The variant stays ahead of the cart: one order, `product_variants`
        // then `carts`, across all three product paths.
        ->and(variantLockPosition($statements))->toBeLessThan($lock);
});

it('locks the cart row before the cart_lines update when a quantity changes', function (): void {
    Event::fake([StockExhausted::class]);
    $this->withBrand();
    $variant = cartLockVariant(10);
    $cart = Cart::factory()->create();
    $line = app(AddProductToCart::class)->handle($cart, $variant, 2);

    $statements = cartLockQueryLog(fn () => app(ChangeCartLineQuantity::class)->handle($line, 5));

    $lock = cartLockPosition($statements);
    $write = cartLineWritePosition($statements);

    expect($lock)->not->toBeNull()
        ->and($write)->not->toBeNull()
        ->and($lock)->toBeLessThan($write)
        ->and(variantLockPosition($statements))->toBeLessThan($lock)
        // Unchanged behaviour: the delta still moves the variant's stock.
        ->and($variant->refresh()->stock_consumed)->toBe(5);
});

it('locks the cart row before the cart_lines delete when a line is removed', function (): void {
    Event::fake([CartLineRemoved::class]);
    $this->withBrand();
    $variant = cartLockVariant(10);
    $cart = Cart::factory()->create();
    $line = app(AddProductToCart::class)->handle($cart, $variant, 3);

    $statements = cartLockQueryLog(fn () => app(RemoveCartLine::class)->handle($line));

    $lock = cartLockPosition($statements);
    $write = cartLineWritePosition($statements);

    expect($lock)->not->toBeNull()
        ->and($write)->not->toBeNull()
        ->and($lock)->toBeLessThan($write)
        ->and(variantLockPosition($statements))->toBeLessThan($lock)
        ->and($variant->refresh()->stock_consumed)->toBe(0)
        ->and(CartLine::query()->whereKey($line->getKey())->exists())->toBeFalse();
});

it('locks the cart row before the cart_lines insert when a registration line is placed', function (): void {
    Event::fake([RegistrationLinePlacedInCart::class]);
    $brand = $this->withBrand();
    $registrantId = (string) Str::ulid();

    // `OpenCart::for()` runs outside the transaction — the very reason two
    // concurrent settlement requests of one buyer meet on the same row — so
    // the cart is opened here and the logged call is the second request.
    app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: $registrantId,
        registrationId: (string) Str::ulid(),
        requestKey: (string) Str::ulid(),
        lines: [['label' => 'Ticket', 'quantity' => 1, 'unit_amount_net' => '50.00', 'tax_class' => 'standard']],
    );

    $statements = cartLockQueryLog(fn () => app(PlaceRegistrationLineInCart::class)->handle(
        brandId: $brand->getKey(),
        registrantUserId: $registrantId,
        registrationId: (string) Str::ulid(),
        requestKey: (string) Str::ulid(),
        lines: [['label' => 'Ticket', 'quantity' => 2, 'unit_amount_net' => '25.00', 'tax_class' => 'standard']],
    ));

    $lock = cartLockPosition($statements);
    $write = cartLineWritePosition($statements);

    expect($lock)->not->toBeNull()
        ->and($write)->not->toBeNull()
        ->and($lock)->toBeLessThan($write)
        ->and(CartLine::query()->count())->toBe(2);
});

it('reads an expiring cart\'s lines in position order and locks its variants in key order', function (): void {
    Event::fake([CartExpired::class, CartLineExpired::class, StockExhausted::class]);
    $this->withBrand();

    $first = cartLockVariant(10);
    $second = cartLockVariant(10);

    $cart = Cart::factory()->create();
    // Deliberately added in the order the ids do *not* have, so "position
    // order" and "key order" are two different orders in this cart.
    [$early, $late] = $first->getKey() < $second->getKey() ? [$second, $first] : [$first, $second];

    app(AddProductToCart::class)->handle($cart, $early, 2);
    app(AddProductToCart::class)->handle($cart, $late, 3);

    $cart->forceFill(['expires_at' => now()->subMinute()])->save();

    $statements = cartLockQueryLog(fn () => app(ExpireCarts::class)->handle());

    $lineReads = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from "cart_lines"'),
    ));

    $variantLocks = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_starts_with($sql, 'select')
            && str_contains($sql, 'from "product_variants"')
            && str_contains($sql, 'for update'),
    ));

    expect($lineReads)->not->toBeEmpty()
        // The sweep reads the lines with a raw query that bypasses
        // `Cart::lines()`; it declares the same `position` order itself.
        ->and($lineReads[0])->toContain('order by "position"')
        // One locking pass over every variant the cart holds, in primary-key
        // order — two carts holding the same two variants at opposite
        // positions would otherwise take them in opposite orders.
        ->and($variantLocks)->toHaveCount(1)
        ->and($variantLocks[0])->toContain('order by "id"')
        // Unchanged behaviour: both holds are given back.
        ->and($early->refresh()->stock_consumed)->toBe(0)
        ->and($late->refresh()->stock_consumed)->toBe(0);
});
