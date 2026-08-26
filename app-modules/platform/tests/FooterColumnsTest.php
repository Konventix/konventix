<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\FooterColumn;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Footer columns are block compositions — the same editing model as a page,
 * restricted to the block types that make sense in a footer.
 */
function footerColumnsOperator(string $host, array $permissions = [
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

it('saves columns per scope, validating each block', function (): void {
    [$operator, $admin] = footerColumnsOperator('admin.footercols.test');

    $this->actingAs($admin)
        ->put('http://admin.footercols.test/admin/settings/footer/columns', [
            'brandId' => null,
            'columns' => [
                ['heading' => 'Contact', 'blocks' => [['type' => 'text', 'data' => ['heading' => null, 'body' => 'Musterstraße 1']]]],
                ['heading' => 'Follow us', 'blocks' => [['type' => 'social-links', 'data' => ['links' => [['network' => 'github', 'url' => 'https://github.com/konventix', 'label' => null]]]]]],
            ],
        ])
        ->assertRedirect();

    $columns = FooterColumn::query()->where('operator_id', $operator->getKey())->orderBy('position')->get();

    expect($columns->pluck('heading')->all())->toBe(['Contact', 'Follow us'])
        ->and($columns[1]->blocks[0]->type)->toBe('social-links');
});

it('refuses a block type that is not a footer block, and a javascript: social url', function (): void {
    [, $admin] = footerColumnsOperator('admin.footercolsbad.test');

    $this->actingAs($admin)
        ->from('http://admin.footercolsbad.test/admin/settings/footer')
        ->put('http://admin.footercolsbad.test/admin/settings/footer/columns', [
            'brandId' => null,
            'columns' => [['heading' => null, 'blocks' => [['type' => 'hero', 'data' => ['heading' => 'x']]]]],
        ])
        ->assertSessionHasErrors('columns.0.blocks.0.type');

    $this->actingAs($admin)
        ->from('http://admin.footercolsbad.test/admin/settings/footer')
        ->put('http://admin.footercolsbad.test/admin/settings/footer/columns', [
            'brandId' => null,
            'columns' => [['heading' => null, 'blocks' => [['type' => 'social-links', 'data' => ['links' => [['network' => 'x', 'url' => 'javascript:alert(1)', 'label' => null]]]]]]],
        ])
        ->assertSessionHasErrors('columns.0.blocks.0.data.links.0.url');
});

it('renders the operator\'s and the brand\'s columns through the block pipeline', function (): void {
    $brand = $this->withBrand();
    $operator = Operator::factory()->create();
    $brand->forceFill(['operator_id' => $operator->getKey()])->save();

    FooterColumn::factory()->create(['operator_id' => $operator->getKey(), 'heading' => 'Contact', 'position' => 0]);
    FooterColumn::factory()->create(['operator_id' => $operator->getKey(), 'brand_id' => $brand->getKey(), 'heading' => 'Ours', 'position' => 0]);
    FooterColumn::factory()->create(['operator_id' => $operator->getKey(), 'brand_id' => Brand::factory()->create(['slug' => 'fc-other'])->getKey(), 'heading' => 'Theirs', 'position' => 0]);

    $this->get('/')->assertInertia(function (AssertableInertia $page): void {
        $columns = collect($page->toArray()['props']['footer']['columns']);

        expect($columns->pluck('heading')->all())->toBe(['Contact', 'Ours'])
            ->and($columns[0]['blocks'][0]['component'])->toBe('platform/blocks/TextBlock');
    });
});
