<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Enums\FooterLinkKind;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\FooterLink;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The operator's footer: one list for every brand it runs, edited as a whole
 * and rendered on every brand-facing page.
 */
function footerOperator(string $host, array $permissions = [
    'platform.access-admin',
    'platform.manage-footer-links',
]): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => $host])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Footer Editor',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

it('saves the footer in the submitted order', function (): void {
    [$operator, $admin] = footerOperator('admin.footer.test');

    $this->actingAs($admin)
        ->put('http://admin.footer.test/admin/settings/footer', [
            'links' => [
                ['label' => 'Imprint', 'kind' => 'url', 'url' => '/pages/imprint', 'opensInNewTab' => false],
                ['label' => 'Cookie settings', 'kind' => 'dialog', 'dialogKey' => 'cookie-settings'],
                ['label' => 'Status', 'kind' => 'url', 'url' => 'https://status.example', 'opensInNewTab' => true],
            ],
        ])
        ->assertRedirect();

    $links = FooterLink::query()
        ->where('operator_id', $operator->getKey())
        ->inDisplayOrder()
        ->get();

    expect($links->pluck('label')->all())->toBe(['Imprint', 'Cookie settings', 'Status'])
        ->and($links->pluck('position')->all())->toBe([0, 1, 2])
        ->and($links[1]->kind)->toBe(FooterLinkKind::Dialog)
        // A dialog entry carries no URL, and only a URL entry may open a tab.
        ->and($links[1]->url)->toBeNull()
        ->and($links[1]->opens_in_new_tab)->toBeFalse()
        ->and($links[2]->opens_in_new_tab)->toBeTrue();
});

it('replaces the whole list rather than appending', function (): void {
    [$operator, $admin] = footerOperator('admin.footerreplace.test');

    FooterLink::factory()->count(3)->create(['operator_id' => $operator->getKey()]);

    $this->actingAs($admin)
        ->put('http://admin.footerreplace.test/admin/settings/footer', [
            'links' => [['label' => 'Only one', 'kind' => 'url', 'url' => '/pages/x']],
        ])
        ->assertRedirect();

    expect(FooterLink::query()->where('operator_id', $operator->getKey())->pluck('label')->all())
        ->toBe(['Only one']);
});

it('refuses a destination that is not a page, an https address or a mailto', function (): void {
    [$operator, $admin] = footerOperator('admin.footerxss.test');

    $this->actingAs($admin)
        ->from('http://admin.footerxss.test/admin/settings/footer')
        ->put('http://admin.footerxss.test/admin/settings/footer', [
            'links' => [['label' => 'Gotcha', 'kind' => 'url', 'url' => 'javascript:alert(1)']],
        ])
        ->assertSessionHasErrors('links.0.url');

    expect(FooterLink::query()->where('operator_id', $operator->getKey())->count())->toBe(0);
});

it('refuses an entry with no destination for its kind', function (): void {
    [, $admin] = footerOperator('admin.footerempty.test');

    $this->actingAs($admin)
        ->from('http://admin.footerempty.test/admin/settings/footer')
        ->put('http://admin.footerempty.test/admin/settings/footer', [
            'links' => [['label' => 'Nowhere', 'kind' => 'dialog', 'dialogKey' => null]],
        ])
        ->assertSessionHasErrors('links.0.dialogKey');
});

it('requires the footer capability', function (): void {
    [, $admin] = footerOperator('admin.footerperm.test', permissions: ['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.footerperm.test/admin/settings/footer')
        ->assertForbidden();
});

it('renders the operator\'s footer on every brand page, in order', function (): void {
    $brand = $this->withBrand();
    $operator = Operator::factory()->create();
    $brand->forceFill(['operator_id' => $operator->getKey()])->save();

    FooterLink::factory()->create([
        'operator_id' => $operator->getKey(),
        'label' => 'Privacy',
        'position' => 1,
    ]);
    FooterLink::factory()->create([
        'operator_id' => $operator->getKey(),
        'label' => 'Imprint',
        'position' => 0,
    ]);

    // A different operator's footer never leaks onto this brand.
    FooterLink::factory()->create([
        'operator_id' => Operator::factory()->create(['admin_host' => 'admin.other-footer.test'])->getKey(),
        'label' => 'Someone else',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $labels = collect($page->toArray()['props']['footer']['links'])->pluck('label')->all();

            expect($labels)->toBe(['Imprint', 'Privacy']);
            expect($page->toArray()['props']['footer']['version'])->toBe(config('app.version'));
        });
});

it('keeps a brand\'s own entries beside the operator-wide ones', function (): void {
    [$operator, $admin] = footerOperator('admin.footerbrand.test');
    $brand = Brand::factory()->create(['slug' => 'ft-brand']);
    $brand->forceFill(['operator_id' => $operator->getKey()])->save();
    FooterLink::factory()->create(['operator_id' => $operator->getKey(), 'label' => 'Imprint', 'position' => 0]);

    $this->actingAs($admin)
        ->put('http://admin.footerbrand.test/admin/settings/footer', [
            'brandId' => $brand->getKey(),
            'links' => [['label' => 'Discord', 'kind' => 'url', 'url' => 'https://discord.gg/x', 'opensInNewTab' => true]],
        ])
        ->assertRedirect();

    $rows = FooterLink::query()->where('operator_id', $operator->getKey())->inDisplayOrder()->get();

    expect($rows->pluck('label')->all())->toBe(['Imprint', 'Discord'])
        ->and($rows->firstWhere('label', 'Discord')->brand_id)->toBe($brand->getKey())
        ->and($rows->firstWhere('label', 'Imprint')->brand_id)->toBeNull();
});

it('replaces only the operator-wide list, leaving a brand\'s own rows untouched', function (): void {
    [$operator, $admin] = footerOperator('admin.footerwidereplace.test');
    $brand = Brand::factory()->create(['slug' => 'ft-widereplace']);
    $brand->forceFill(['operator_id' => $operator->getKey()])->save();

    FooterLink::factory()->create(['operator_id' => $operator->getKey(), 'label' => 'Old operator-wide']);
    FooterLink::factory()->create(['operator_id' => $operator->getKey(), 'brand_id' => $brand->getKey(), 'label' => 'Brand-only']);

    $this->actingAs($admin)
        ->put('http://admin.footerwidereplace.test/admin/settings/footer', [
            'links' => [['label' => 'New operator-wide', 'kind' => 'url', 'url' => '/pages/x']],
        ])
        ->assertRedirect();

    $rows = FooterLink::query()->where('operator_id', $operator->getKey())->inDisplayOrder()->get();

    expect($rows->pluck('label')->all())->toBe(['New operator-wide', 'Brand-only'])
        ->and($rows->firstWhere('label', 'Brand-only')->brand_id)->toBe($brand->getKey());
});

it('refuses a brand that is not the operator\'s', function (): void {
    [, $admin] = footerOperator('admin.footerforeign.test');
    $foreign = Brand::factory()->create(['slug' => 'ft-foreign']);

    $this->actingAs($admin)
        ->from('http://admin.footerforeign.test/admin/settings/footer')
        ->put('http://admin.footerforeign.test/admin/settings/footer', [
            'brandId' => $foreign->getKey(),
            'links' => [['label' => 'X', 'kind' => 'url', 'url' => '/x']],
        ])
        ->assertSessionHasErrors('brandId');
});

it('renders operator rows on every brand and a brand\'s rows only on its own host', function (): void {
    $brand = $this->withBrand();
    $operator = Operator::factory()->create();
    $brand->forceFill(['operator_id' => $operator->getKey()])->save();
    $sibling = Brand::factory()->withDomain('sibling.footer.test')->create(['slug' => 'ft-sibling']);
    $sibling->forceFill(['operator_id' => $operator->getKey()])->save();

    FooterLink::factory()->create(['operator_id' => $operator->getKey(), 'label' => 'Imprint', 'position' => 0]);
    FooterLink::factory()->create(['operator_id' => $operator->getKey(), 'brand_id' => $brand->getKey(), 'label' => 'Ours', 'position' => 0]);
    FooterLink::factory()->create(['operator_id' => $operator->getKey(), 'brand_id' => $sibling->getKey(), 'label' => 'Theirs', 'position' => 0]);

    $this->get('/')->assertInertia(fn (AssertableInertia $page) => expect(
        collect($page->toArray()['props']['footer']['links'])->pluck('label')->all()
    )->toBe(['Imprint', 'Ours']));

    $this->get('http://sibling.footer.test/')->assertInertia(fn (AssertableInertia $page) => expect(
        collect($page->toArray()['props']['footer']['links'])->pluck('label')->all()
    )->toBe(['Imprint', 'Theirs']));
});
