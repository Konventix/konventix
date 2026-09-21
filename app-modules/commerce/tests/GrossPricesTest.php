<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Enums\PriceDisplay;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Event\Models\Event;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Registration\Data\PriceDeclarationData;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\RegistrationType;

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

/**
 * #234 (D-COM-20): one calculator, one answer. A registration type that
 * declares the same net amount as a product must print the same end price —
 * and the defect was exactly this pair disagreeing on one page, the
 * Commerce card showing the PAngV end price while the Registration card
 * beside it showed the net amount with no tax note at all.
 *
 * The two arrive by different roads: the product through
 * `ShopProductData::ofModel()`, the offer through Platform's
 * `Pricing\EndPriceCalculator`, which Commerce binds to the same
 * `TaxCalculator`. Asserting them against each other rather than against a
 * literal is the point — the day the rate changes, they still have to
 * agree.
 */
it('gives a contributed registration offer the same end price as a product of the same net amount', function (): void {
    grossPricesOperator();

    $product = Product::factory()->published()->create([
        'name' => 'Event T-Shirt',
        'tax_class' => TaxClass::Standard,
        'unit_amount_net' => '19.00',
        'price_display' => PriceDisplay::Gross,
    ]);
    ProductVariant::factory()->create(['product_id' => $product->getKey()]);

    $event = Event::factory()->published()->create();
    EventSummary::query()->create([
        'event_id' => $event->getKey(),
        'name' => 'Wintercon',
        'slug' => 'wintercon',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
        'public_since' => Carbon::now()->subWeek(),
    ]);
    RegistrationType::factory()->forEvent($event->getKey())->create([
        'name' => 'Weekend Ticket',
        'price_declaration' => new PriceDeclarationData(
            '19.00',
            Konventix\Registration\Enums\TaxClass::Standard,
            Konventix\Registration\Enums\PriceDisplay::Gross,
        ),
    ]);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            $offer = collect($props['slots'])
                ->firstWhere('component', 'registration/ShopOffers')['props']['offers'][0];

            expect($props['products'][0]['unitAmountGross'])->toBe('22.61')
                ->and($offer['endPrice']['unitAmountGross'])->toBe($props['products'][0]['unitAmountGross'])
                ->and($offer['endPrice']['currency'])->toBe($props['currency'])
                // And the same answer to "does this amount contain VAT?",
                // which is what the note beside each of them claims.
                ->and($offer['endPrice']['includesTax'])->toBe(! $props['smallBusinessScheme']);
        });
});
