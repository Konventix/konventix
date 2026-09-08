<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Enums\PriceDisplay;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;

/**
 * PAngV: the price a consumer is shown is the end price (#3), and the
 * purchase path says so in words (#75). The gross amount is computed
 * server-side by `TaxCalculator` — never in the browser — so the shop, the
 * cart and the invoice can only ever agree.
 *
 * Helper names prefixed `gross*`: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function grossPricesOperator(bool $smallBusinessScheme = false): void
{
    $brand = test()->withBrand();

    $factory = Operator::factory();

    if ($smallBusinessScheme) {
        $factory = $factory->smallBusinessScheme();
    }

    $brand->operator()->associate($factory->create())->save();

    test()->activateFeature('commerce:shop', $brand);
}

it('carries a server-computed gross unit amount on the shop listing', function (): void {
    grossPricesOperator();

    $product = Product::factory()->published()->create([
        'name' => 'Event T-Shirt',
        'tax_class' => TaxClass::Standard,
        'unit_amount_net' => '19.00',
        'price_display' => PriceDisplay::Gross,
    ]);
    ProductVariant::factory()->create(['product_id' => $product->getKey()]);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('shop/Index')
            ->where('products.0.unitAmountNet', '19.00')
            ->where('products.0.unitAmountGross', '22.61')
            ->where('products.0.priceDisplay', 'gross'));
});

it('carries a gross amount per variant, including one with its own price', function (): void {
    grossPricesOperator();

    $product = Product::factory()->published()->create([
        'tax_class' => TaxClass::Standard,
        'unit_amount_net' => '19.00',
    ]);
    ProductVariant::factory()->create([
        'product_id' => $product->getKey(),
        'label' => 'M',
        'unit_amount_net' => null,
        'position' => 0,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $product->getKey(),
        'label' => 'XXL',
        'unit_amount_net' => '25.00',
        'position' => 1,
    ]);

    $this->get('/shop/'.$product->slug)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('shop/Show')
            ->where('product.unitAmountGross', '22.61')
            ->where('product.variants.0.unitAmountNet', '19.00')
            ->where('product.variants.0.unitAmountGross', '22.61')
            ->where('product.variants.1.unitAmountNet', '25.00')
            ->where('product.variants.1.unitAmountGross', '29.75'));
});

it('shows the reduced rate where the product declares it', function (): void {
    grossPricesOperator();

    $product = Product::factory()->published()->create([
        'tax_class' => TaxClass::Reduced,
        'unit_amount_net' => '100.00',
    ]);
    ProductVariant::factory()->create(['product_id' => $product->getKey()]);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.0.unitAmountGross', '107.00'));
});

it('shows gross equal to net for an operator on the small-business scheme', function (): void {
    grossPricesOperator(smallBusinessScheme: true);

    $product = Product::factory()->published()->create([
        'tax_class' => TaxClass::Standard,
        'unit_amount_net' => '19.00',
    ]);
    ProductVariant::factory()->create(['product_id' => $product->getKey()]);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.0.unitAmountGross', '19.00'));
});

it('shows the same per-unit gross in the cart as in the shop', function (): void {
    grossPricesOperator();
    $this->actingAs(User::factory()->create());

    $product = Product::factory()->published()->create([
        'tax_class' => TaxClass::Standard,
        'unit_amount_net' => '19.00',
    ]);
    $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);

    $this->post('/cart/lines', ['variantId' => $variant->getKey(), 'quantity' => 2])
        ->assertRedirect();

    $this->get('/cart')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('cart/Show')
            ->where('cart.lines.0.unitAmountNet', '19.00')
            ->where('cart.lines.0.unitAmountGross', '22.61')
            ->where('cart.lines.0.lineGross', '45.22'));
});
