<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Enums\BrandMenuItemKind;
use Konventix\Platform\Enums\PageVisibility;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandMenuItem;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Models\StaticPage;
use Spatie\Permission\PermissionRegistrar;

/**
 * A brand's topbar menu (Task 20): edited as a whole on the console, one
 * level of hierarchy, page entries bound to the brand's own pages.
 *
 * @return array{0: Operator, 1: User, 2: Brand}
 */
function brandMenuOperator(string $host, array $permissions = ['platform.access-admin', 'platform.manage-brand-menu']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => $host])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create(['name' => 'Menu Editor', 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    $brand = Brand::factory()->create(['slug' => 'menu-'.str_replace('.', '-', $host)]);
    $brand->forceFill(['operator_id' => $operator->getKey()])->save();

    return [$operator, $admin, $brand];
}

function brandMenuPage(Brand $brand, array $attributes = []): StaticPage
{
    $page = StaticPage::factory()->published()->make($attributes);
    $page->brand_id = $brand->getKey();
    $page->save();

    return $page;
}

it('saves the menu in the submitted order, one level deep', function (): void {
    [, $admin, $brand] = brandMenuOperator('admin.menu.test');
    $about = brandMenuPage($brand, ['slug' => 'about', 'title' => 'About us']);
    $rules = brandMenuPage($brand, ['slug' => 'rules', 'title' => 'House rules']);

    $this->actingAs($admin)
        ->put('http://admin.menu.test/admin/menu', [
            'brandId' => $brand->getKey(),
            'items' => [
                ['key' => 'a', 'kind' => 'page', 'pageId' => $about->getKey(), 'label' => ''],
                ['key' => 'more', 'kind' => 'parent', 'label' => 'More'],
                ['key' => 'r', 'kind' => 'page', 'pageId' => $rules->getKey(), 'label' => 'Rules', 'parentKey' => 'more'],
                ['key' => 'd', 'kind' => 'url', 'label' => 'Discord', 'url' => 'https://discord.gg/x', 'opensInNewTab' => true, 'parentKey' => 'more'],
                ['key' => 's', 'kind' => 'url', 'label' => 'Shop', 'url' => '/shop'],
            ],
        ])
        ->assertRedirect('http://admin.menu.test/admin/menu?brand='.$brand->getKey());

    $rows = BrandMenuItem::withoutBrandScope()->forBrand($brand->getKey())->inDisplayOrder()->get();
    $more = $rows->firstWhere('label', 'More');

    expect($rows->whereNull('parent_id')->pluck('position')->all())->toBe([0, 1, 2])
        ->and($rows->whereNull('parent_id')->map(fn (BrandMenuItem $row): ?string => $row->label)->all())->toBe([null, 'More', 'Shop'])
        ->and($rows->where('parent_id', $more->getKey())->pluck('label')->all())->toBe(['Rules', 'Discord'])
        ->and($rows->where('parent_id', $more->getKey())->pluck('position')->all())->toBe([0, 1])
        ->and($rows->firstWhere('label', 'Discord')->opens_in_new_tab)->toBeTrue()
        ->and($rows->first()->kind)->toBe(BrandMenuItemKind::Page)
        ->and($rows->first()->static_page_id)->toBe($about->getKey())
        // A page entry carries no URL, a URL entry no page.
        ->and($rows->first()->url)->toBeNull()
        ->and($rows->firstWhere('label', 'Shop')->static_page_id)->toBeNull();
});

it('replaces the whole menu rather than appending', function (): void {
    [, $admin, $brand] = brandMenuOperator('admin.menureplace.test');
    $old = BrandMenuItem::factory()->make(['label' => 'Old']);
    $old->brand_id = $brand->getKey();
    $old->save();

    $this->actingAs($admin)
        ->put('http://admin.menureplace.test/admin/menu', [
            'brandId' => $brand->getKey(),
            'items' => [['key' => 'n', 'kind' => 'url', 'label' => 'New', 'url' => '/new']],
        ])
        ->assertRedirect();

    expect(BrandMenuItem::withoutBrandScope()->forBrand($brand->getKey())->pluck('label')->all())->toBe(['New']);
});

it('refuses a group inside a group', function (): void {
    [, $admin, $brand] = brandMenuOperator('admin.menudepth.test');

    $this->actingAs($admin)
        ->from('http://admin.menudepth.test/admin/menu')
        ->put('http://admin.menudepth.test/admin/menu', [
            'brandId' => $brand->getKey(),
            'items' => [
                ['key' => 'outer', 'kind' => 'parent', 'label' => 'Outer'],
                ['key' => 'inner', 'kind' => 'parent', 'label' => 'Inner', 'parentKey' => 'outer'],
            ],
        ])
        ->assertSessionHasErrors('items.1.parentKey');

    expect(BrandMenuItem::withoutBrandScope()->forBrand($brand->getKey())->count())->toBe(0);
});

it('refuses a child whose parent is not a group of this menu', function (): void {
    [, $admin, $brand] = brandMenuOperator('admin.menuorphan.test');

    $this->actingAs($admin)
        ->from('http://admin.menuorphan.test/admin/menu')
        ->put('http://admin.menuorphan.test/admin/menu', [
            'brandId' => $brand->getKey(),
            'items' => [
                ['key' => 'link', 'kind' => 'url', 'label' => 'Link', 'url' => '/x'],
                ['key' => 'child', 'kind' => 'url', 'label' => 'Child', 'url' => '/y', 'parentKey' => 'link'],
            ],
        ])
        ->assertSessionHasErrors('items.1.parentKey');
});

it('refuses a destination that is not a path, an https address or a mailto', function (): void {
    [, $admin, $brand] = brandMenuOperator('admin.menuxss.test');

    $this->actingAs($admin)
        ->from('http://admin.menuxss.test/admin/menu')
        ->put('http://admin.menuxss.test/admin/menu', [
            'brandId' => $brand->getKey(),
            'items' => [['key' => 'x', 'kind' => 'url', 'label' => 'Gotcha', 'url' => 'javascript:alert(1)']],
        ])
        ->assertSessionHasErrors('items.0.url');

    expect(BrandMenuItem::withoutBrandScope()->forBrand($brand->getKey())->count())->toBe(0);
});

it('refuses a page of another brand and an entry with no destination', function (): void {
    [, $admin, $brand] = brandMenuOperator('admin.menuforeignpage.test');
    $foreign = brandMenuPage(Brand::factory()->create(['slug' => 'menu-foreign-page']), ['slug' => 'theirs']);

    $this->actingAs($admin)
        ->from('http://admin.menuforeignpage.test/admin/menu')
        ->put('http://admin.menuforeignpage.test/admin/menu', [
            'brandId' => $brand->getKey(),
            'items' => [['key' => 'p', 'kind' => 'page', 'pageId' => $foreign->getKey()]],
        ])
        ->assertSessionHasErrors('items.0.pageId');

    $this->actingAs($admin)
        ->from('http://admin.menuforeignpage.test/admin/menu')
        ->put('http://admin.menuforeignpage.test/admin/menu', [
            'brandId' => $brand->getKey(),
            'items' => [['key' => 'g', 'kind' => 'parent', 'label' => '']],
        ])
        ->assertSessionHasErrors('items.0.label');
});

it('refuses a brand that is not the operator\'s', function (): void {
    [, $admin] = brandMenuOperator('admin.menuforeign.test');
    $foreign = Brand::factory()->create(['slug' => 'menu-foreign']);

    $this->actingAs($admin)
        ->from('http://admin.menuforeign.test/admin/menu')
        ->put('http://admin.menuforeign.test/admin/menu', [
            'brandId' => $foreign->getKey(),
            'items' => [['key' => 'x', 'kind' => 'url', 'label' => 'X', 'url' => '/x']],
        ])
        ->assertSessionHasErrors('brandId');
});

it('requires the menu capability', function (): void {
    [, $admin, $brand] = brandMenuOperator('admin.menuperm.test', permissions: ['platform.access-admin']);

    $this->actingAs($admin)->get('http://admin.menuperm.test/admin/menu')->assertForbidden();
    $this->actingAs($admin)
        ->put('http://admin.menuperm.test/admin/menu', ['brandId' => $brand->getKey(), 'items' => []])
        ->assertForbidden();
});

it('hands the editor the picked brand\'s entries and pages, defaulting to no brand', function (): void {
    [, $admin, $brand] = brandMenuOperator('admin.menuedit.test');
    $page = brandMenuPage($brand, ['slug' => 'about', 'title' => 'About', 'visibility' => PageVisibility::Members]);
    $item = BrandMenuItem::factory()->forPage($page)->make(['position' => 0]);
    $item->brand_id = $brand->getKey();
    $item->save();

    $this->actingAs($admin)
        ->get('http://admin.menuedit.test/admin/menu?brand='.$brand->getKey())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $response) => $response
            ->component('admin/pages/Menu')
            ->where('brand.id', $brand->getKey())
            ->where('items.0.key', $item->getKey())
            ->where('items.0.kind', 'page')
            ->where('items.0.pageId', $page->getKey())
            ->where('pages.0.id', $page->getKey())
            ->where('pages.0.visibility', 'members'));

    $this->actingAs($admin)
        ->get('http://admin.menuedit.test/admin/menu')
        ->assertInertia(fn (AssertableInertia $response) => $response->where('brand', null)->where('items', null)->has('brands'));
});
