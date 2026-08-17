<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\StaticPage;

/**
 * The `/` route (routes/web.php): renders the current brand's published
 * `home`-slug static page through the block pipeline when one exists, falls
 * back to the minimal Welcome page otherwise, and never leaks one brand's
 * home page onto another brand's domain (ADR-0010).
 */
it('renders the brand\'s home page through the block pipeline', function (): void {
    $this->withBrand();

    StaticPage::factory()->published()->create([
        'slug' => 'home',
        'title' => 'Welcome Home',
        'blocks' => [
            ['type' => 'hero', 'data' => [
                'heading' => 'Hello',
                'subheading' => null,
                'ctaLabel' => null,
                'ctaUrl' => null,
                'imageMediaId' => null,
            ]],
        ],
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('StaticPage')
            ->where('title', 'Welcome Home')
            ->where('blocks.0.component', 'platform/blocks/HeroBlock')
            ->where('blocks.0.props.heading', 'Hello'));
});

it('falls back to the Welcome page when the brand has no home page at all', function (): void {
    $this->withBrand();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
});

it('falls back to the Welcome page when the home page exists but is not published', function (): void {
    $this->withBrand();

    StaticPage::factory()->create(['slug' => 'home']);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
});

it('does not render another brand\'s home page', function (): void {
    $this->withBrand();

    StaticPage::factory()->published()->create(['slug' => 'home', 'title' => 'Brand A Home']);

    Brand::factory()->withDomain('other-brand.test')->create();

    $this->get('http://other-brand.test/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Welcome'));
});
