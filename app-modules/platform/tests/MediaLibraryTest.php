<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Konventix\Identity\Models\User;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0035: the brand-scoped media library — uploads with variants, the
 * ULID-only read API, usage references, and the delete guard.
 */
function mediaOperator(array $permissions = ['platform.access-admin', 'platform.view-media', 'platform.manage-media']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.media.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

it('uploads a file with a generated thumb variant and lists it', function (): void {
    [, $admin] = mediaOperator();
    Storage::fake('media');

    $brand = $this->withBrand();

    $this->actingAs($admin)
        ->post('http://admin.media.test/admin/media', [
            'brand_id' => $brand->id,
            'file' => UploadedFile::fake()->image('logo.png', 1200, 800),
            'alt_text' => 'Brand logo',
        ])
        ->assertRedirect();

    $item = MediaItem::withoutBrandScope()->firstOrFail();

    expect($item->brand_id)->toBe($brand->id)
        ->and($item->original_filename)->toBe('logo.png')
        ->and($item->alt_text)->toBe('Brand logo')
        ->and($item->uploaded_by_user_id)->toBe($admin->id)
        ->and($item->variants)->toHaveKey('thumb');

    Storage::disk('media')->assertExists($item->path);
    Storage::disk('media')->assertExists($item->variants['thumb']);

    $this->actingAs($admin)
        ->get('http://admin.media.test/admin/media')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/media/Index')
            ->has('media.data', 1)
            ->where('media.data.0.originalFilename', 'logo.png')
            // An ISO instant, not a preformatted UTC string (#238): the page
            // renders it in the reader's own zone through `formatDateTime()`.
            ->where('media.data.0.uploadedAt', fn (string $at): bool => (bool) preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
                $at,
            ))
            ->where('media.data.0.usageCount', 0));
});

it('403s uploads without the manage-media capability', function (): void {
    [, $admin] = mediaOperator(['platform.access-admin', 'platform.view-media']);
    Storage::fake('media');

    $brand = $this->withBrand();

    $this->actingAs($admin)
        ->post('http://admin.media.test/admin/media', [
            'brand_id' => $brand->id,
            'file' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertForbidden();
});

it('resolves media ULIDs to URLs and degrades stale references to null', function (): void {
    Storage::fake('media');
    $this->withBrand();

    $item = MediaItem::factory()->create(['variants' => ['thumb' => 'x/thumb.webp']]);
    $library = app(MediaLibrary::class);

    expect($library->urlFor($item->id))->toContain($item->path)
        ->and($library->urlFor($item->id, 'thumb'))->toContain('x/thumb.webp')
        ->and($library->urlFor($item->id, 'missing-variant'))->toBeNull()
        ->and($library->urlFor('01KZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeNull();
});

it('records usages idempotently and refuses to delete a referenced item', function (): void {
    [, $admin] = mediaOperator();
    Storage::fake('media');
    $this->withBrand();

    $item = MediaItem::factory()->create();
    $library = app(MediaLibrary::class);

    $library->recordUsage($item->id, 'content', 'entry', '01JZZZZZZZZZZZZZZZZZZZZZZ1');
    $library->recordUsage($item->id, 'content', 'entry', '01JZZZZZZZZZZZZZZZZZZZZZZ1');

    expect($item->usages()->count())->toBe(1);

    $this->actingAs($admin)
        ->delete("http://admin.media.test/admin/media/{$item->id}")
        ->assertSessionHasErrors('media');

    expect(MediaItem::withoutBrandScope()->whereKey($item->id)->exists())->toBeTrue();

    $library->releaseUsage($item->id, 'content', 'entry', '01JZZZZZZZZZZZZZZZZZZZZZZ1');

    $this->actingAs($admin)
        ->delete("http://admin.media.test/admin/media/{$item->id}")
        ->assertRedirect();

    expect(MediaItem::withoutBrandScope()->whereKey($item->id)->exists())->toBeFalse();
});
