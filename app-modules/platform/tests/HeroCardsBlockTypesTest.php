<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Models\MediaItem;
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
                'imageMediaId' => null,
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

/**
 * ADR-0043: the hero payload stores a media ULID, and the URL is resolved at
 * render time through MediaLibrary — page content must not bake in which disk
 * the media currently lives on.
 */
it('resolves a hero block\'s media ULID into a url and the library\'s alt text', function (): void {
    $this->withBrand();

    $media = MediaItem::factory()->create(['alt_text' => 'The main hall at capacity']);

    StaticPage::factory()->published()->create([
        'slug' => 'hero-media-page',
        'blocks' => [
            ['type' => 'hero', 'data' => [
                'heading' => 'Welcome',
                'subheading' => null,
                'ctaLabel' => null,
                'ctaUrl' => null,
                'imageMediaId' => $media->getKey(),
            ]],
        ],
    ]);

    $this->get('/pages/hero-media-page')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('blocks.0.props.imageUrl', Storage::disk('media')->url($media->path))
            ->where('blocks.0.props.imageAlt', 'The main hall at capacity'));
});

it('renders a hero block without an image when its media ULID no longer resolves', function (): void {
    $this->withBrand();

    StaticPage::factory()->published()->create([
        'slug' => 'hero-stale-page',
        'blocks' => [
            ['type' => 'hero', 'data' => [
                'heading' => 'Welcome',
                'subheading' => null,
                'ctaLabel' => null,
                'ctaUrl' => null,
                'imageMediaId' => (string) Str::ulid(),
            ]],
        ],
    ]);

    // A stale reference degrades to a hero without an image, exactly as
    // MediaLibrary's own contract does — it never fails the page.
    $this->get('/pages/hero-stale-page')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('blocks.0.props.heading', 'Welcome')
            ->where('blocks.0.props.imageUrl', null)
            ->where('blocks.0.props.imageAlt', null));
});

it('migrates stored hero payloads from a resolved url to a media ULID', function (): void {
    $this->withBrand();

    $media = MediaItem::factory()->create();

    $page = StaticPage::factory()->published()->create([
        'slug' => 'legacy-hero-page',
        'blocks' => [
            ['type' => 'hero', 'data' => [
                'heading' => 'Welcome',
                'subheading' => null,
                'ctaLabel' => null,
                'ctaUrl' => null,
                'imageUrl' => Storage::disk('media')->url($media->path),
            ]],
            ['type' => 'text', 'data' => ['heading' => 'Untouched', 'body' => 'Still here']],
        ],
    ]);

    $migration = require base_path(
        'app-modules/platform/database/migrations/2026_08_17_120000_hero_blocks_reference_media_by_ulid.php'
    );
    $migration->up();

    $blocks = $page->fresh()->blocks;

    expect($blocks[0]->data)->toHaveKey('imageMediaId')
        ->and($blocks[0]->data['imageMediaId'])->toBe($media->getKey())
        ->and($blocks[0]->data)->not->toHaveKey('imageUrl')
        // Blocks of other types are left exactly as they were.
        ->and($blocks[1]->data['heading'])->toBe('Untouched');
});

it('drops a hero url that matches no media item rather than failing the migration', function (): void {
    $this->withBrand();

    $page = StaticPage::factory()->published()->create([
        'slug' => 'orphan-hero-page',
        'blocks' => [
            ['type' => 'hero', 'data' => [
                'heading' => 'Welcome',
                'subheading' => null,
                'ctaLabel' => null,
                'ctaUrl' => null,
                'imageUrl' => 'https://example.test/hand-written.png',
            ]],
        ],
    ]);

    $migration = require base_path(
        'app-modules/platform/database/migrations/2026_08_17_120000_hero_blocks_reference_media_by_ulid.php'
    );
    $migration->up();

    expect($page->fresh()->blocks[0]->data['imageMediaId'])->toBeNull();
});
