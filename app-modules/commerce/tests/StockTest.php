<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Enums\PriceDisplay;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Platform\Actions\MaterializeDefaultRoles;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Permission as PermissionRow;
use Konventix\Platform\Models\Role;

/**
 * The shop's first data (ADR-0047, roadmap slice 24): `Product`/
 * `ProductVariant` and stock as a quota Commerce keeps under lock — the
 * exact contract `Registration\Models\Quota` uses, copied not imported.
 */
it('reduces remaining() when consumeStock() runs under lockForUpdate() inside a transaction', function (): void {
    $this->withBrand();

    $variant = ProductVariant::factory()->withCapacity(10)->consumed(3)->create();

    DB::transaction(function () use ($variant): void {
        $locked = ProductVariant::query()->lockForUpdate()->findOrFail($variant->getKey());
        $locked->consumeStock(2);
    });

    expect($variant->refresh()->remaining())->toBe(5)
        ->and($variant->stock_consumed)->toBe(5);
});

it('refuses stock beyond capacity through hasStockFor()', function (): void {
    $this->withBrand();

    $variant = ProductVariant::factory()->withCapacity(5)->consumed(3)->create();

    expect($variant->hasStockFor(2))->toBeTrue()
        ->and($variant->hasStockFor(3))->toBeFalse()
        ->and($variant->remaining())->toBe(2);
});

it('floors releaseStock() at 0, like Quota::release()', function (): void {
    $this->withBrand();

    $variant = ProductVariant::factory()->withCapacity(10)->consumed(2)->create();

    $variant->releaseStock(5);

    expect($variant->stock_consumed)->toBe(0)
        ->and($variant->remaining())->toBe(10);
});

it('reports a product sold out only once every variant has nothing left', function (): void {
    $this->withBrand();

    $product = Product::factory()->create();
    $inStock = ProductVariant::factory()->for($product, 'product')->withCapacity(5)->consumed(1)->create();
    $exhausted = ProductVariant::factory()->for($product, 'product')->exhausted()->create();

    expect($product->fresh(['variants'])->isSoldOut())->toBeFalse();

    $inStock->consumeStock($inStock->remaining());

    expect($product->fresh(['variants'])->isSoldOut())->toBeTrue()
        ->and($exhausted->remaining())->toBe(0);
});

it('orders variants() by position', function (): void {
    $this->withBrand();

    $product = Product::factory()->create();
    ProductVariant::factory()->for($product, 'product')->create(['position' => 2, 'label' => 'L']);
    ProductVariant::factory()->for($product, 'product')->create(['position' => 0, 'label' => 'S']);
    ProductVariant::factory()->for($product, 'product')->create(['position' => 1, 'label' => 'M']);

    expect($product->variants()->pluck('label')->all())->toBe(['S', 'M', 'L']);
});

it('scopePublished() only returns products published in the past', function (): void {
    $this->withBrand();

    $published = Product::factory()->published()->create();
    Product::factory()->create(['published_at' => null]);
    Product::factory()->create(['published_at' => now()->addWeek()]);

    expect(Product::query()->published()->pluck('id')->all())->toBe([$published->id]);
});

it('effectiveUnitAmountNet() falls back to the product price when the variant declares none', function (): void {
    $this->withBrand();

    $product = Product::factory()->create(['unit_amount_net' => '25.00']);
    $default = ProductVariant::factory()->for($product, 'product')->create(['unit_amount_net' => null]);
    $override = ProductVariant::factory()->for($product, 'product')->create(['unit_amount_net' => '30.00']);

    expect($default->effectiveUnitAmountNet())->toBe('25.00')
        ->and($override->effectiveUnitAmountNet())->toBe('30.00');
});

it('casts a product\'s tax class and price display', function (): void {
    $this->withBrand();

    $product = Product::factory()->create([
        'tax_class' => TaxClass::Reduced,
        'price_display' => PriceDisplay::Net,
    ]);

    expect($product->fresh()->tax_class)->toBe(TaxClass::Reduced)
        ->and($product->fresh()->price_display)->toBe(PriceDisplay::Net);
});

it('syncs commerce.view-products and commerce.manage-products as permission rows', function (): void {
    $this->artisan('permissions:sync', ['--no-roles' => true])->assertSuccessful();

    expect(PermissionRow::query()->where('name', Permission::ViewProducts->value)->exists())->toBeTrue()
        ->and(PermissionRow::query()->where('name', Permission::ManageProducts->value)->exists())->toBeTrue();
});

it('materializes commerce.shop-manager with exactly its three grants', function (): void {
    $brand = Brand::factory()->create(['slug' => 'stock-shop-manager']);

    app(MaterializeDefaultRoles::class)->handle($brand);

    $role = Role::query()
        ->where('brand_id', $brand->getKey())
        ->where('default_key', 'commerce.shop-manager')
        ->first();

    expect($role)->not->toBeNull()
        ->and($role->name)->toBe('Shop Manager')
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe([
            Permission::ManageProducts->value,
            Permission::ViewOrders->value,
            Permission::ViewProducts->value,
        ]);
});
