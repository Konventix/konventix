<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Enums\CartLineKind;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Commerce\Models\Cart;
use Konventix\Commerce\Models\CartLine;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Identity\Models\User;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\MediaUsage;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Operators\ConsoleEventSources;
use Spatie\Permission\PermissionRegistrar;

/**
 * The product console (ADR-0047, Task 7): a brand's catalogue, its variants,
 * and stock as a quota Commerce keeps under lock.
 */
function productsOperator(array $permissions = ['platform.access-admin', 'commerce.view-products', 'commerce.manage-products']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.products.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create(['name' => 'Shop Manager', 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

function productOf(Brand $brand, array $attributes = []): Product
{
    $product = Product::factory()->make($attributes);
    $product->brand_id = $brand->getKey();
    $product->save();

    return $product;
}

/**
 * @return array<string, mixed>
 */
function productPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Con Shirt',
        'slug' => 'con-shirt',
        'description' => null,
        'eventId' => null,
        'imageMediaId' => null,
        'taxClass' => 'standard',
        'unitAmountNet' => '19.00',
        'priceDisplay' => 'gross',
        'published' => false,
        'stockCapacity' => 50,
        'variants' => [],
    ], $overrides);
}

it('lists the selected brand\'s products and searches them', function (): void {
    [, $admin] = productsOperator();
    $brand = Brand::factory()->create(['slug' => 'pr-list']);
    productOf($brand, ['name' => 'Con Shirt', 'slug' => 'con-shirt']);
    productOf($brand, ['name' => 'Con Mug', 'slug' => 'con-mug']);
    productOf(Brand::factory()->create(['slug' => 'pr-other']), ['name' => 'Elsewhere', 'slug' => 'elsewhere']);

    $this->actingAs($admin)
        ->get('http://admin.products.test/admin/products?brand='.$brand->getKey().'&search=shirt')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/products/Index')
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Con Shirt'));
});

it('creates a product with an implicit variant, resolving the brand from the console selection', function (): void {
    [, $admin] = productsOperator();
    $brand = Brand::factory()->create(['slug' => 'pr-create']);

    $this->actingAs($admin)
        ->put('http://admin.products.test/admin/selection', ['brandId' => $brand->getKey(), 'eventId' => null])
        ->assertRedirect();

    $this->actingAs($admin)
        ->post('http://admin.products.test/admin/products', productPayload(['stockCapacity' => 25]))
        ->assertRedirect();

    $product = Product::withoutBrandScope()->where('brand_id', $brand->getKey())->where('slug', 'con-shirt')->sole();
    expect($product->published_at)->toBeNull();

    $variants = ProductVariant::withoutBrandScope()->where('product_id', $product->getKey())->get();
    expect($variants)->toHaveCount(1)
        ->and($variants->first()->label)->toBeNull()
        ->and($variants->first()->stock_capacity)->toBe(25);
});

it('refuses to create a product when no brand is selected', function (): void {
    [, $admin] = productsOperator();

    $this->actingAs($admin)
        ->post('http://admin.products.test/admin/products', productPayload())
        ->assertSessionHasErrors('brand');
});

it('refuses a duplicate slug on the same brand', function (): void {
    [, $admin] = productsOperator();
    $brand = Brand::factory()->create(['slug' => 'pr-dup']);
    productOf($brand, ['slug' => 'con-shirt']);
    $other = productOf($brand, ['slug' => 'con-mug']);

    $this->actingAs($admin)
        ->put("http://admin.products.test/admin/products/{$other->getKey()}", productPayload(['slug' => 'con-shirt']))
        ->assertSessionHasErrors('slug');
});

it('syncs variants on update: adds, renames and removes rows', function (): void {
    [, $admin] = productsOperator();
    $brand = $this->withBrand(Brand::factory()->create(['slug' => 'pr-sync']));
    $product = productOf($brand);
    $kept = ProductVariant::factory()->for($product, 'product')->create(['label' => 'Small', 'position' => 0, 'stock_capacity' => 10]);
    $removed = ProductVariant::factory()->for($product, 'product')->create(['label' => 'Medium', 'position' => 1, 'stock_capacity' => 10]);

    $this->actingAs($admin)
        ->put("http://admin.products.test/admin/products/{$product->getKey()}", productPayload([
            'variants' => [
                ['id' => $kept->getKey(), 'label' => 'Small (renamed)', 'sku' => null, 'unitAmountNet' => null, 'stockCapacity' => 12, 'position' => 0],
                ['id' => null, 'label' => 'Large', 'sku' => null, 'unitAmountNet' => null, 'stockCapacity' => 8, 'position' => 1],
            ],
        ]))
        ->assertRedirect();

    $variants = ProductVariant::withoutBrandScope()->where('product_id', $product->getKey())->orderBy('position')->get();
    expect($variants)->toHaveCount(2)
        ->and($variants[0]->label)->toBe('Small (renamed)')
        ->and($variants[0]->stock_capacity)->toBe(12)
        ->and($variants[1]->label)->toBe('Large')
        ->and(ProductVariant::withoutBrandScope()->whereKey($removed->getKey())->exists())->toBeFalse();
});

it('refuses a variant capacity below stock already consumed', function (): void {
    [, $admin] = productsOperator();
    $brand = $this->withBrand(Brand::factory()->create(['slug' => 'pr-capacity']));
    $product = productOf($brand);
    $variant = ProductVariant::factory()->for($product, 'product')->create(['stock_capacity' => 10, 'stock_consumed' => 6, 'position' => 0]);

    $this->actingAs($admin)
        ->put("http://admin.products.test/admin/products/{$product->getKey()}", productPayload([
            'variants' => [
                ['id' => $variant->getKey(), 'label' => null, 'sku' => null, 'unitAmountNet' => null, 'stockCapacity' => 3, 'position' => 0],
            ],
        ]))
        ->assertSessionHasErrors('variants.0.stockCapacity');

    expect($variant->refresh()->stock_capacity)->toBe(10);
});

it('reuses the sole implicit variant across saves, applying the capacity guard and keeping stock_consumed', function (): void {
    [, $admin] = productsOperator();
    $brand = $this->withBrand(Brand::factory()->create(['slug' => 'pr-implicit-reuse']));
    $product = productOf($brand);
    $variant = ProductVariant::factory()->for($product, 'product')->create(['position' => 0, 'stock_capacity' => 30]);
    $variant->forceFill(['stock_consumed' => 20])->save();

    // A variant-less save must land on the SAME row — reusing its id, not
    // deleting it and minting a fresh `stock_consumed = 0` one — so the
    // ordinary capacity-vs-consumed guard applies here too, keyed to the
    // field the operator actually edited (`stockCapacity`, not
    // `variants.0.stockCapacity`, which has no field on this form).
    $this->actingAs($admin)
        ->put("http://admin.products.test/admin/products/{$product->getKey()}", productPayload(['stockCapacity' => 10]))
        ->assertSessionHasErrors('stockCapacity');

    expect(ProductVariant::withoutBrandScope()->where('product_id', $product->getKey())->count())->toBe(1)
        ->and($variant->refresh()->stock_capacity)->toBe(30)
        ->and($variant->stock_consumed)->toBe(20);

    $this->actingAs($admin)
        ->put("http://admin.products.test/admin/products/{$product->getKey()}", productPayload(['stockCapacity' => 30]))
        ->assertRedirect();

    $variants = ProductVariant::withoutBrandScope()->where('product_id', $product->getKey())->get();
    expect($variants)->toHaveCount(1)
        ->and($variants->first()->getKey())->toBe($variant->getKey())
        ->and($variants->first()->stock_capacity)->toBe(30)
        ->and($variants->first()->stock_consumed)->toBe(20);
});

it('records and releases image usage as the product\'s image changes', function (): void {
    [, $admin] = productsOperator();
    $brand = Brand::factory()->create(['slug' => 'pr-media']);
    $product = productOf($brand);
    $image = MediaItem::factory()->make();
    $image->brand_id = $brand->getKey();
    $image->save();

    $this->actingAs($admin)
        ->put("http://admin.products.test/admin/products/{$product->getKey()}", productPayload(['imageMediaId' => $image->getKey()]))
        ->assertRedirect();

    expect(MediaUsage::withoutBrandScope()->where('media_item_id', $image->getKey())->where('entity_type', 'product')->where('entity_id', $product->getKey())->exists())->toBeTrue();

    $this->actingAs($admin)
        ->put("http://admin.products.test/admin/products/{$product->getKey()}", productPayload(['imageMediaId' => null]))
        ->assertRedirect();

    expect(MediaUsage::withoutBrandScope()->where('media_item_id', $image->getKey())->exists())->toBeFalse();
});

it('refuses an image belonging to a different brand', function (): void {
    [, $admin] = productsOperator();
    $brand = Brand::factory()->create(['slug' => 'pr-foreign']);
    $product = productOf($brand);
    $foreign = Brand::factory()->create(['slug' => 'pr-foreign-other']);
    $image = MediaItem::factory()->make();
    $image->brand_id = $foreign->getKey();
    $image->save();

    $this->actingAs($admin)
        ->put("http://admin.products.test/admin/products/{$product->getKey()}", productPayload(['imageMediaId' => $image->getKey()]))
        ->assertSessionHasErrors('imageMediaId');
});

it('toggles publication', function (): void {
    [, $admin] = productsOperator();
    $brand = Brand::factory()->create(['slug' => 'pr-publish']);
    $product = productOf($brand);

    $this->actingAs($admin)
        ->put("http://admin.products.test/admin/products/{$product->getKey()}", productPayload(['published' => true]))
        ->assertRedirect();

    expect($product->refresh()->published_at)->not->toBeNull();

    $this->actingAs($admin)
        ->put("http://admin.products.test/admin/products/{$product->getKey()}", productPayload(['published' => false]))
        ->assertRedirect();

    expect($product->refresh()->published_at)->toBeNull();
});

it('hands the editor the brand\'s images and the console\'s events', function (): void {
    [, $admin] = productsOperator();
    $brand = Brand::factory()->create(['slug' => 'pr-edit']);
    $product = productOf($brand);
    $image = MediaItem::factory()->make();
    $image->brand_id = $brand->getKey();
    $image->save();

    // The console's event selector is fed by whichever module owns events;
    // this test stands in for it rather than reaching into Event.
    app(ConsoleEventSources::class)->add(fn (?string $brandId): array => [
        ['id' => 'evt_01', 'name' => 'SXLan 2027', 'brandId' => $brand->getKey()],
    ]);

    $this->actingAs($admin)
        ->get("http://admin.products.test/admin/products/{$product->getKey()}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/products/Edit')
            ->where('product.id', $product->getKey())
            ->has('media', 1)
            ->where('events', [
                ['id' => 'evt_01', 'name' => 'SXLan 2027', 'brandId' => $brand->getKey()],
            ]));
});

it('deletes a product and its variants, releasing the image it referenced', function (): void {
    [, $admin] = productsOperator();
    $brand = $this->withBrand(Brand::factory()->create(['slug' => 'pr-delete']));
    $image = MediaItem::factory()->make();
    $image->brand_id = $brand->getKey();
    $image->save();

    // Set up directly rather than through an admin-console update — this
    // test is about the delete cascade, not the save sync.
    $product = productOf($brand, ['image_media_id' => $image->getKey()]);
    $variant = ProductVariant::factory()->for($product, 'product')->create();
    app(MediaLibrary::class)->recordUsage($image->getKey(), 'commerce', 'product', $product->getKey());

    $this->actingAs($admin)
        ->delete("http://admin.products.test/admin/products/{$product->getKey()}")
        ->assertRedirect('http://admin.products.test/admin/products');

    expect(Product::withoutBrandScope()->whereKey($product->getKey())->exists())->toBeFalse()
        ->and(ProductVariant::withoutBrandScope()->whereKey($variant->getKey())->exists())->toBeFalse()
        ->and(MediaUsage::withoutBrandScope()->where('media_item_id', $image->getKey())->exists())->toBeFalse();
});

it('refuses to delete a product whose variant sits in an open cart, but not once that cart expires', function (): void {
    [, $admin] = productsOperator();
    $brand = $this->withBrand(Brand::factory()->create(['slug' => 'pr-cart-delete']));
    $product = productOf($brand);
    $variant = ProductVariant::factory()->for($product, 'product')->create();
    $cart = Cart::factory()->create(['status' => CartStatus::Open]);
    CartLine::factory()->forCart($cart)->create(['kind' => CartLineKind::Product, 'ref_id' => $variant->getKey()]);

    $this->actingAs($admin)
        ->delete("http://admin.products.test/admin/products/{$product->getKey()}")
        ->assertSessionHasErrors('product');

    expect(Product::withoutBrandScope()->whereKey($product->getKey())->exists())->toBeTrue();

    // An expired cart's line does not count — its stock has already been
    // released by the sweep (Actions\ExpireCarts).
    $cart->forceFill(['status' => CartStatus::Expired])->save();

    $this->actingAs($admin)
        ->delete("http://admin.products.test/admin/products/{$product->getKey()}")
        ->assertRedirect('http://admin.products.test/admin/products');

    expect(Product::withoutBrandScope()->whereKey($product->getKey())->exists())->toBeFalse();
});

it('refuses to drop a variant from the list while an open cart still holds it, but not once that cart expires', function (): void {
    [, $admin] = productsOperator();
    $brand = $this->withBrand(Brand::factory()->create(['slug' => 'pr-cart-variant']));
    $product = productOf($brand);
    $kept = ProductVariant::factory()->for($product, 'product')->create(['label' => 'Small', 'position' => 0]);
    $referenced = ProductVariant::factory()->for($product, 'product')->create(['label' => 'Medium', 'position' => 1]);
    $cart = Cart::factory()->create(['status' => CartStatus::Open]);
    CartLine::factory()->forCart($cart)->create(['kind' => CartLineKind::Product, 'ref_id' => $referenced->getKey()]);

    $updatePayload = productPayload([
        'variants' => [
            ['id' => $kept->getKey(), 'label' => 'Small', 'sku' => null, 'unitAmountNet' => null, 'stockCapacity' => 10, 'position' => 0],
        ],
    ]);

    $this->actingAs($admin)
        ->put("http://admin.products.test/admin/products/{$product->getKey()}", $updatePayload)
        ->assertSessionHasErrors('variants');

    expect(ProductVariant::withoutBrandScope()->whereKey($referenced->getKey())->exists())->toBeTrue();

    $cart->forceFill(['status' => CartStatus::Expired])->save();

    $this->actingAs($admin)
        ->put("http://admin.products.test/admin/products/{$product->getKey()}", $updatePayload)
        ->assertRedirect();

    expect(ProductVariant::withoutBrandScope()->whereKey($referenced->getKey())->exists())->toBeFalse();
});

it('refuses to delete a product whose variant a settled order line still settles against', function (): void {
    [, $admin] = productsOperator();
    $brand = $this->withBrand(Brand::factory()->create(['slug' => 'pr-order-ref']));
    $product = productOf($brand);
    $variant = ProductVariant::factory()->for($product, 'product')->create();
    OrderLine::factory()->create(['settles_kind' => 'product', 'settles_ref' => $variant->getKey()]);

    $this->actingAs($admin)
        ->delete("http://admin.products.test/admin/products/{$product->getKey()}")
        ->assertSessionHasErrors('product');

    expect(Product::withoutBrandScope()->whereKey($product->getKey())->exists())->toBeTrue();
});

it('requires view-products to read and manage-products to write', function (): void {
    [, $reader] = productsOperator(['platform.access-admin', 'commerce.view-products']);
    $brand = Brand::factory()->create(['slug' => 'pr-perm']);
    $product = productOf($brand);

    $this->actingAs($reader)->get('http://admin.products.test/admin/products')->assertOk();
    $this->actingAs($reader)
        ->put("http://admin.products.test/admin/products/{$product->getKey()}", productPayload())
        ->assertForbidden();
});
