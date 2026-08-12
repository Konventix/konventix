<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\StaticPage;

/**
 * The block render pipeline: stored `{type, data}` entries resolve through the
 * registry into `{component, props}`; unknown or inactive types are skipped,
 * never errors.
 */
it('renders a published page\'s blocks through the registry', function (): void {
    $this->withBrand();

    StaticPage::factory()->published()->create([
        'slug' => 'imprint',
        'title' => 'Imprint',
        'blocks' => [
            ['type' => 'text', 'data' => ['heading' => 'Kontakt', 'body' => 'Konventix Dev Operator']],
        ],
    ]);

    $this->get('/pages/imprint')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('StaticPage')
            ->where('title', 'Imprint')
            ->where('blocks.0.component', 'platform/blocks/TextBlock')
            ->where('blocks.0.props.heading', 'Kontakt')
            ->where('blocks.0.props.body', 'Konventix Dev Operator'));
});

it('404s an unpublished page', function (): void {
    $this->withBrand();

    StaticPage::factory()->create(['slug' => 'draft-page']);

    $this->get('/pages/draft-page')->assertNotFound();
});

it('skips a block whose type nobody registered', function (): void {
    $this->withBrand();

    StaticPage::factory()->published()->create([
        'slug' => 'partial',
        'blocks' => [
            ['type' => 'from-an-uninstalled-module', 'data' => []],
            ['type' => 'text', 'data' => ['heading' => null, 'body' => 'Still here.']],
        ],
    ]);

    $this->get('/pages/partial')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('blocks.0.props.body', 'Still here.')
            ->count('blocks', 1));
});

it('does not serve another brand\'s page', function (): void {
    $this->withBrand();

    StaticPage::factory()->published()->create(['slug' => 'ours']);

    // A second brand on its own host must not see the first brand's page.
    Brand::factory()->withDomain('elsewhere.test')->create();

    $this->get('http://elsewhere.test/pages/ours')->assertNotFound();
});
