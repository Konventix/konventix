<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Models\StaticPage;

/**
 * Platform's `hero` and `cards` block types — registered at boot alongside
 * `text` (ADR-0003), rendered through the same pipeline as
 * `StaticPageRenderingTest`.
 */
it('registers the hero and cards block types at boot', function (): void {
    $types = app(BlockRegistry::class)->all();

    expect($types)->toHaveKeys(['hero', 'cards']);
    expect($types['hero']->component())->toBe('platform/blocks/HeroBlock');
    expect($types['hero']->module())->toBe('platform');
    expect($types['cards']->component())->toBe('platform/blocks/CardsBlock');
    expect($types['cards']->module())->toBe('platform');
});

it('renders a hero block\'s props through the registry', function (): void {
    $this->withBrand();

    StaticPage::factory()->published()->create([
        'slug' => 'hero-page',
        'blocks' => [
            ['type' => 'hero', 'data' => [
                'heading' => 'Welcome',
                'subheading' => 'Doors open soon',
                'ctaLabel' => 'Register now',
                'ctaUrl' => '/register',
                'imageUrl' => null,
            ]],
        ],
    ]);

    $this->get('/pages/hero-page')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('StaticPage')
            ->where('blocks.0.component', 'platform/blocks/HeroBlock')
            ->where('blocks.0.props.heading', 'Welcome')
            ->where('blocks.0.props.ctaUrl', '/register'));
});

it('renders a cards block\'s items through the registry', function (): void {
    $this->withBrand();

    StaticPage::factory()->published()->create([
        'slug' => 'cards-page',
        'blocks' => [
            ['type' => 'cards', 'data' => ['cards' => [
                ['title' => 'One', 'body' => 'First', 'icon' => 'ticket'],
                ['title' => 'Two', 'body' => 'Second', 'icon' => null],
            ]]],
        ],
    ]);

    $this->get('/pages/cards-page')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('blocks.0.component', 'platform/blocks/CardsBlock')
            ->where('blocks.0.props.cards.0.title', 'One')
            ->where('blocks.0.props.cards.0.icon', 'ticket')
            ->where('blocks.0.props.cards.1.icon', null));
});
