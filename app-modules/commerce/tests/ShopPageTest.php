<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Event\Models\Event;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;

/**
 * The public shop (ADR-0047, Task 8): `/shop` and `/shop/{slug}`, resolved
 * on the brand's own domain, behind the `commerce:shop` feature Task 5
 * introduced with no module gate behind it.
 *
 * Helper names prefixed `shop*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function shopLandingSlot(AssertableInertia $page): ?array
{
    return collect($page->toArray()['props']['slots'])
        ->firstWhere('component', 'commerce/ShopLandingCard');
}

it('404s the shop when the brand has not switched the feature on', function (): void {
    $this->withBrand();

    $this->get('/shop')->assertNotFound();
    $this->get('/shop/anything')->assertNotFound();
});

it('lists only published products, and keeps another brand out of the listing', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $published = Product::factory()->published()->create(['name' => 'Event T-Shirt']);
    ProductVariant::factory()->create(['product_id' => $published->getKey()]);

    $draft = Product::factory()->create(['name' => 'Unreleased Hoodie']);
    ProductVariant::factory()->create(['product_id' => $draft->getKey()]);

    // A product that belongs to another brand entirely, created while the
    // context is switched — the same technique MyOrdersTest uses for cross-
    // brand isolation.
    $this->withBrand(Brand::factory()->create());
    $theirs = Product::factory()->published()->create(['name' => 'Someone Else\'s Mug']);
    ProductVariant::factory()->create(['product_id' => $theirs->getKey()]);
    $this->withBrand($brand);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('shop/Index')
            ->has('products', 1)
            ->where('products.0.name', 'Event T-Shirt')
            ->where('products.0.slug', $published->slug));
});

it('404s the product page for an unpublished product and for another brand\'s slug', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $draft = Product::factory()->create(['slug' => 'unreleased-hoodie']);
    ProductVariant::factory()->create(['product_id' => $draft->getKey()]);

    $this->get('/shop/'.$draft->slug)->assertNotFound();

    $this->withBrand(Brand::factory()->create());
    $theirs = Product::factory()->published()->create(['slug' => 'someone-elses-mug']);
    ProductVariant::factory()->create(['product_id' => $theirs->getKey()]);
    $this->withBrand($brand);

    $this->get('/shop/'.$theirs->slug)->assertNotFound();
});

it('flags a sold-out product on the index and disables it on the product page', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $soldOut = Product::factory()->published()->create(['name' => 'Limited Poster']);
    ProductVariant::factory()->exhausted()->create(['product_id' => $soldOut->getKey()]);

    $inStock = Product::factory()->published()->create(['name' => 'Event T-Shirt']);
    ProductVariant::factory()->withCapacity(10)->create(['product_id' => $inStock->getKey()]);

    $this->get('/shop')
        ->assertInertia(function (AssertableInertia $page): void {
            $rows = collect($page->toArray()['props']['products'])->keyBy('name');

            expect($rows['Limited Poster']['soldOut'])->toBeTrue()
                ->and($rows['Event T-Shirt']['soldOut'])->toBeFalse();
        });

    $this->get('/shop/'.$soldOut->slug)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('shop/Show')
            ->where('product.soldOut', true)
            ->where('product.variants.0.remaining', 0));
});

it('lists a product\'s variants with what remains of each on the show page', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $product = Product::factory()->published()->create(['name' => 'Event T-Shirt']);
    ProductVariant::factory()->withCapacity(30)->consumed(10)->create([
        'product_id' => $product->getKey(),
        'label' => 'S',
        'position' => 0,
    ]);
    ProductVariant::factory()->withCapacity(20)->consumed(20)->create([
        'product_id' => $product->getKey(),
        'label' => 'M',
        'position' => 1,
    ]);

    $this->get('/shop/'.$product->slug)
        ->assertInertia(function (AssertableInertia $page): void {
            $variants = collect($page->toArray()['props']['product']['variants'])->keyBy('label');

            expect($variants['S']['remaining'])->toBe(20)
                ->and($variants['M']['remaining'])->toBe(0);
        });
});

it('carries the signed-in state a guest and a member differ on', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $product = Product::factory()->published()->create();
    ProductVariant::factory()->create(['product_id' => $product->getKey()]);

    // The page itself decides between the disabled buy button and a
    // "Sign in to buy" link from this — Inertia feature tests do not render
    // Vue, so what is under test here is that the guest state actually
    // reaches the page rather than the rendered markup.
    $this->get('/shop/'.$product->slug)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user', null));

    $member = User::factory()->create();

    $this->actingAs($member)
        ->get('/shop/'.$product->slug)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.id', $member->getKey()));
});

it('shows the Shop entry in the topbar only once the feature is active', function (): void {
    $brand = $this->withBrand();

    $this->get('/')
        ->assertInertia(function (AssertableInertia $page): void {
            $entry = collect($page->toArray()['props']['nav'])->firstWhere('label', 'Shop');

            expect($entry)->toBeNull();
        });

    $this->activateFeature('commerce:shop', $brand);

    $this->get('/')
        ->assertInertia(function (AssertableInertia $page): void {
            $entry = collect($page->toArray()['props']['nav'])->firstWhere('label', 'Shop');

            expect($entry)->not->toBeNull()
                ->and($entry['url'])->toBe('/shop');
        });
});

it('contributes the landing card to an event with published products', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $event = Event::factory()->published()->create();

    $product = Product::factory()->published()->create([
        'event_id' => $event->getKey(),
        'name' => 'Event T-Shirt',
    ]);
    ProductVariant::factory()->create(['product_id' => $product->getKey()]);

    // A product tied to a different event never shows up on this one's page.
    $other = Event::factory()->published()->create();
    $elsewhere = Product::factory()->published()->create(['event_id' => $other->getKey()]);
    ProductVariant::factory()->create(['product_id' => $elsewhere->getKey()]);

    $this->get('/events/'.$event->slug)
        ->assertInertia(function (AssertableInertia $page) use ($product): void {
            $slot = shopLandingSlot($page);

            expect($slot)->not->toBeNull()
                ->and($slot['props']['products'])->toHaveCount(1)
                ->and($slot['props']['products'][0]['id'])->toBe($product->getKey());
        });
});

it('carries no landing-card slot when the feature is off', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    $this->get('/events/'.$event->slug)
        ->assertInertia(fn (AssertableInertia $page) => expect(shopLandingSlot($page))->toBeNull());
});

/**
 * GitLab #240: the shop is a page other modules contribute to, not a
 * product list. Registration hangs its own types off `shop.index` — the
 * same direction Commerce contributes `ShopLandingCard` to Event's landing
 * page — so this page must carry the slot bag whether or not anything is
 * in it, and Commerce never learns what is (ADR-0004).
 */
it('carries the shop.index slot bag for other modules to hang offers off', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('shop/Index')
            ->has('slots'));
});

/**
 * ADR-0030 / branch review finding 12: under the operator's §19 election the
 * calculator puts no VAT on any line, so the card may not label the amount
 * "incl. VAT". The page is told which case it is in; the wording itself is
 * `purchaseTexts::taxNote()`'s, pinned in its own unit test.
 */
it('tells the shop pages whether the price contains any VAT at all', function (): void {
    $brand = $this->withBrand();
    $this->activateFeature('commerce:shop', $brand);

    $product = Product::factory()->published()->create(['name' => 'Event T-Shirt']);
    ProductVariant::factory()->create(['product_id' => $product->getKey()]);

    $this->get('/shop')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('smallBusinessScheme', false));

    $this->get('/shop/'.$product->slug)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('smallBusinessScheme', false));
});
