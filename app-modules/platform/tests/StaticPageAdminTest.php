<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Blocks\BlockType;
use Konventix\Platform\Blocks\HasBlockEditor;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Data\Blocks\TextBlockData;
use Konventix\Platform\Enums\PageVisibility;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandMenuItem;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\MediaUsage;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Models\StaticPage;
use Spatie\Permission\PermissionRegistrar;

/**
 * The first block editor (ROADMAP slice 21a): pages of a brand, composed of
 * registered block types, validated on save, media usages recorded.
 */
function pagesOperator(array $permissions = ['platform.access-admin', 'platform.view-static-pages', 'platform.manage-static-pages']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.pages.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create(['name' => 'Page Editor', 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

function pageOf(Brand $brand, array $attributes = []): StaticPage
{
    $page = StaticPage::factory()->make($attributes);
    $page->brand_id = $brand->getKey();
    $page->save();

    return $page;
}

it('lists the selected brand\'s pages and searches them', function (): void {
    [, $admin] = pagesOperator();
    $brand = Brand::factory()->create(['slug' => 'pg-list']);
    pageOf($brand, ['title' => 'Imprint', 'slug' => 'imprint']);
    pageOf($brand, ['title' => 'About us', 'slug' => 'about']);
    pageOf(Brand::factory()->create(['slug' => 'pg-other']), ['title' => 'Elsewhere', 'slug' => 'elsewhere']);

    $this->actingAs($admin)
        ->get('http://admin.pages.test/admin/pages?brand='.$brand->getKey().'&search=imp')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/pages/Index')
            ->has('pages.data', 1)
            ->where('pages.data.0.title', 'Imprint'));
});

it('creates a page on the chosen brand and refuses a duplicate slug there', function (): void {
    [, $admin] = pagesOperator();
    $brand = Brand::factory()->create(['slug' => 'pg-create']);

    $this->actingAs($admin)
        ->post('http://admin.pages.test/admin/pages', ['title' => 'Imprint', 'slug' => 'imprint', 'brandId' => $brand->getKey()])
        ->assertRedirect();

    $page = StaticPage::withoutBrandScope()->where('brand_id', $brand->getKey())->where('slug', 'imprint')->sole();
    expect($page->published_at)->toBeNull();

    $this->actingAs($admin)
        ->from('http://admin.pages.test/admin/pages')
        ->post('http://admin.pages.test/admin/pages', ['title' => 'Again', 'slug' => 'imprint', 'brandId' => $brand->getKey()])
        ->assertSessionHasErrors('slug');
});

it('saves a validated composition, records media usages, and publishes', function (): void {
    [, $admin] = pagesOperator();
    $brand = Brand::factory()->create(['slug' => 'pg-save']);
    $page = pageOf($brand, ['slug' => 'home']);
    $image = MediaItem::factory()->make();
    $image->brand_id = $brand->getKey();
    $image->save();

    $this->actingAs($admin)
        ->put("http://admin.pages.test/admin/pages/{$page->getKey()}", [
            'title' => 'Welcome',
            'slug' => 'home',
            'published' => true,
            'blocks' => [
                ['type' => 'hero', 'data' => ['heading' => 'Hi', 'subheading' => null, 'ctaLabel' => null, 'ctaUrl' => null, 'imageMediaId' => $image->getKey()]],
                ['type' => 'text', 'data' => ['heading' => null, 'body' => 'Body']],
            ],
        ])
        ->assertRedirect();

    $page->refresh();
    expect($page->published_at)->not->toBeNull()
        ->and($page->blocks)->toHaveCount(2)
        ->and(MediaUsage::withoutBrandScope()->where('media_item_id', $image->getKey())->where('entity_type', 'static_page')->exists())->toBeTrue();

    // Dropping the hero releases its usage; unpublishing clears the date.
    $this->actingAs($admin)
        ->put("http://admin.pages.test/admin/pages/{$page->getKey()}", [
            'title' => 'Welcome',
            'slug' => 'home',
            'published' => false,
            'blocks' => [['type' => 'text', 'data' => ['heading' => null, 'body' => 'Body']]],
        ])
        ->assertRedirect();

    expect($page->refresh()->published_at)->toBeNull()
        ->and(MediaUsage::withoutBrandScope()->where('media_item_id', $image->getKey())->exists())->toBeFalse();
});

it('refuses a hero block that references another brand\'s media', function (): void {
    [, $admin] = pagesOperator();
    $brand = Brand::factory()->create(['slug' => 'pg-foreign']);
    $page = pageOf($brand);
    $foreign = Brand::factory()->create(['slug' => 'pg-foreign-other']);
    $image = MediaItem::factory()->make();
    $image->brand_id = $foreign->getKey();
    $image->save();

    $this->actingAs($admin)
        ->from("http://admin.pages.test/admin/pages/{$page->getKey()}/edit")
        ->put("http://admin.pages.test/admin/pages/{$page->getKey()}", [
            'title' => 'x',
            'slug' => 'x',
            'published' => false,
            'blocks' => [
                ['type' => 'hero', 'data' => ['heading' => 'Hi', 'subheading' => null, 'ctaLabel' => null, 'ctaUrl' => null, 'imageMediaId' => $image->getKey()]],
            ],
        ])
        ->assertSessionHasErrors('blocks');

    expect(MediaUsage::withoutBrandScope()->where('media_item_id', $image->getKey())->exists())->toBeFalse();
});

it('keys a block validation error to the block that caused it', function (): void {
    [, $admin] = pagesOperator();
    $brand = Brand::factory()->create(['slug' => 'pg-invalid']);
    $page = pageOf($brand);

    $this->actingAs($admin)
        ->from("http://admin.pages.test/admin/pages/{$page->getKey()}/edit")
        ->put("http://admin.pages.test/admin/pages/{$page->getKey()}", [
            'title' => 'x',
            'slug' => 'x',
            'published' => false,
            'blocks' => [
                ['type' => 'text', 'data' => ['heading' => null, 'body' => 'ok']],
                ['type' => 'hero', 'data' => ['subheading' => 'missing heading']],
            ],
        ])
        ->assertSessionHasErrors('blocks.1.data.heading');
});

it('refuses a block of a module that is not active for the brand', function (): void {
    [, $admin] = pagesOperator();
    $brand = Brand::factory()->create(['slug' => 'pg-gated']);
    $page = pageOf($brand);

    // Competition ships no block type; a gateable module that does is what
    // this guards — so register one for the test.
    $fake = new class implements BlockType, HasBlockEditor
    {
        public function key(): string
        {
            return 'gated-test-block';
        }

        public function module(): string
        {
            return 'competition';
        }

        public function component(): string
        {
            return 'competition/blocks/Gated';
        }

        public function dataClass(): string
        {
            return TextBlockData::class;
        }

        public function label(): string
        {
            return 'Gated';
        }

        public function editorComponent(): string
        {
            return 'competition/blocks/editors/Gated';
        }

        public function defaultData(): array
        {
            return ['heading' => null, 'body' => ''];
        }
    };
    app(BlockRegistry::class)->register($fake);

    $this->actingAs($admin)
        ->from("http://admin.pages.test/admin/pages/{$page->getKey()}/edit")
        ->put("http://admin.pages.test/admin/pages/{$page->getKey()}", [
            'title' => 'x', 'slug' => 'x', 'published' => false,
            'blocks' => [['type' => 'gated-test-block', 'data' => ['heading' => null, 'body' => 'x']]],
        ])
        ->assertSessionHasErrors('blocks.0.type');
});

it('hands the editor the brand\'s images, the console\'s events and the editable types', function (): void {
    [, $admin] = pagesOperator();
    $brand = Brand::factory()->withDomain('pages-edit.test')->create(['slug' => 'pg-edit']);
    $page = pageOf($brand, ['published_at' => now()]);
    $home = pageOf($brand, ['slug' => 'home', 'published_at' => now()]);

    $this->actingAs($admin)
        ->get("http://admin.pages.test/admin/pages/{$page->getKey()}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $response) => $response
            ->component('admin/pages/Edit')
            ->where('page.id', $page->getKey())
            ->where('page.publicUrl', app(BrandUrl::class)->baseFor($brand).'/pages/'.$page->slug)
            ->has('types')
            ->has('context.media')
            // The brand the picker uploads into (#292).
            ->where('context.brandId', $brand->getKey())
            ->has('context.events'));

    // The `home` slug links to the brand's root, not `/pages/home`.
    $this->actingAs($admin)
        ->get("http://admin.pages.test/admin/pages/{$home->getKey()}/edit")
        ->assertInertia(fn (AssertableInertia $response) => $response->where('page.publicUrl', app(BrandUrl::class)->baseFor($brand).'/'));
});

it('deletes a page and releases what it referenced', function (): void {
    [, $admin] = pagesOperator();
    $brand = Brand::factory()->create(['slug' => 'pg-delete']);
    $page = pageOf($brand);
    $image = MediaItem::factory()->make();
    $image->brand_id = $brand->getKey();
    $image->save();

    $this->actingAs($admin)
        ->put("http://admin.pages.test/admin/pages/{$page->getKey()}", [
            'title' => 'Welcome',
            'slug' => $page->slug,
            'published' => false,
            'blocks' => [
                ['type' => 'hero', 'data' => ['heading' => 'Hi', 'subheading' => null, 'ctaLabel' => null, 'ctaUrl' => null, 'imageMediaId' => $image->getKey()]],
            ],
        ])
        ->assertRedirect();

    expect(MediaUsage::withoutBrandScope()->where('media_item_id', $image->getKey())->exists())->toBeTrue();

    $this->actingAs($admin)
        ->delete("http://admin.pages.test/admin/pages/{$page->getKey()}")
        ->assertRedirect('http://admin.pages.test/admin/pages');

    expect(StaticPage::withoutBrandScope()->whereKey($page->getKey())->exists())->toBeFalse()
        ->and(MediaUsage::withoutBrandScope()->where('media_item_id', $image->getKey())->exists())->toBeFalse();
});

it('requires view-static-pages to read and manage-static-pages to write', function (): void {
    [, $reader] = pagesOperator(['platform.access-admin', 'platform.view-static-pages']);
    $brand = Brand::factory()->create(['slug' => 'pg-perm']);
    $page = pageOf($brand);

    $this->actingAs($reader)->get('http://admin.pages.test/admin/pages')->assertOk();
    $this->actingAs($reader)->put("http://admin.pages.test/admin/pages/{$page->getKey()}", ['title' => 'x', 'slug' => 'x', 'published' => false, 'blocks' => []])->assertForbidden();
});

it('round-trips visibility and keeps the show-in-menu shortcut idempotent', function (): void {
    [, $admin] = pagesOperator();
    $brand = Brand::factory()->create(['slug' => 'pg-visibility']);
    $page = pageOf($brand, ['slug' => 'lounge']);

    $save = fn (array $extra) => $this->actingAs($admin)
        ->put("http://admin.pages.test/admin/pages/{$page->getKey()}", [
            'title' => 'Lounge', 'slug' => 'lounge', 'published' => true, 'blocks' => [], ...$extra,
        ])
        ->assertRedirect();

    $entries = fn () => BrandMenuItem::withoutBrandScope()->forBrand($brand->getKey())->where('static_page_id', $page->getKey());

    $save(['visibility' => 'members', 'showInMenu' => true]);
    $save(['visibility' => 'members', 'showInMenu' => true]);

    expect($page->refresh()->visibility)->toBe(PageVisibility::Members)
        ->and($entries()->count())->toBe(1)
        ->and($entries()->sole()->parent_id)->toBeNull();

    $this->actingAs($admin)
        ->get("http://admin.pages.test/admin/pages/{$page->getKey()}/edit")
        ->assertInertia(fn (AssertableInertia $response) => $response
            ->where('page.visibility', 'members')
            ->where('page.inMenu', true));

    // Omitted: the menu is left alone. Explicitly off: the entry goes.
    $save(['visibility' => 'registrants']);
    expect($page->refresh()->visibility)->toBe(PageVisibility::Registrants)
        ->and($entries()->count())->toBe(1);

    $save(['showInMenu' => false]);
    $save(['showInMenu' => false]);
    expect($entries()->count())->toBe(0)
        // No visibility in the submit means public — the form always sends
        // one; this is the default for a caller that never heard of it.
        ->and($page->refresh()->visibility)->toBe(PageVisibility::Public);
});
