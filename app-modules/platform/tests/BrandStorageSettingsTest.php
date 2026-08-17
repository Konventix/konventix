<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\StoreMediaUpload;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandStorageSetting;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Storage\DiskResolver;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0043 point 6: a brand may bring its own bucket, carried as brand data
 * the way a per-brand mail transport is (ADR-0028). The disk name recorded on
 * each media row is what makes that a data migration rather than a cutover —
 * items uploaded before the switch keep resolving from where they were
 * written.
 */
it('sends new uploads to the deployment disk for a brand with no settings', function (): void {
    $brand = $this->withBrand();

    expect(app(DiskResolver::class)->mediaDiskNameFor($brand->getKey()))->toBe('media');
});

it('sends new uploads to the brand\'s own bucket once it has settings', function (): void {
    $brand = $this->withBrand();
    BrandStorageSetting::factory()->create(['bucket' => 'sxlan-media']);

    $resolver = app(DiskResolver::class);
    $diskName = $resolver->mediaDiskNameFor($brand->getKey());

    expect($diskName)->toBe('media:'.$brand->getKey())
        ->and($resolver->exists($diskName))->toBeTrue()
        ->and($resolver->configFor($diskName))->toMatchArray([
            'driver' => 's3',
            'bucket' => 'sxlan-media',
            'root' => 'media',
            'key' => 'GKtest',
        ]);

    // Credentials are encrypted at rest — the column is ciphertext, not JSON.
    $stored = (string) DB::table('brand_storage_settings')->value('credentials');
    expect($stored)->not->toContain('GKtest');
});

it('records the brand disk on the item and writes there', function (): void {
    $brand = $this->withBrand();
    BrandStorageSetting::factory()->create(['bucket' => 'sxlan-media']);

    $diskName = 'media:'.$brand->getKey();
    app(DiskResolver::class)->use($diskName, Storage::fake('brand-bucket'));

    $item = app(StoreMediaUpload::class)->handle(
        brandId: $brand->getKey(),
        file: UploadedFile::fake()->image('logo.png', 800, 600),
        altText: null,
        uploadedByUserId: null,
    );

    expect($item->disk)->toBe($diskName);
    Storage::disk('brand-bucket')->assertExists($item->path);
});

it('serves a brand bucket through the application unless it is public_direct', function (): void {
    $brand = $this->withBrand();
    BrandStorageSetting::factory()->create(['bucket' => 'sxlan-media']);

    $item = MediaItem::factory()->create(['disk' => 'media:'.$brand->getKey()]);

    expect(app(MediaLibrary::class)->urlFor($item->getKey()))
        ->toBe(route('platform.media.show', ['media' => $item->getKey()]));
});

it('hands out the brand\'s own url when its bucket is public', function (): void {
    $brand = $this->withBrand();
    BrandStorageSetting::factory()
        ->publicDirect('https://cdn.sxlan.test')
        ->create(['bucket' => 'sxlan-media', 'prefix' => 'media']);

    $item = MediaItem::factory()->create([
        'disk' => 'media:'.$brand->getKey(),
        'path' => $brand->getKey().'/logo.png',
    ]);

    // The bucket prefix is part of the key, so it is part of the URL too.
    expect(app(MediaLibrary::class)->urlFor($item->getKey()))
        ->toBe('https://cdn.sxlan.test/media/'.$brand->getKey().'/logo.png');
});

it('degrades to a 404 when a brand\'s storage settings are gone', function (): void {
    $brand = $this->withBrand();
    $setting = BrandStorageSetting::factory()->create(['bucket' => 'sxlan-media']);

    $item = MediaItem::factory()->create(['disk' => 'media:'.$brand->getKey()]);

    $setting->delete();

    // The row still names a disk nobody can build — the media route says so
    // rather than throwing, and storage:doctor reports the orphaned rows.
    $this->get(route('platform.media.show', ['media' => $item->getKey()]))->assertNotFound();
});

it('resolves two brands to two different buckets in one process', function (): void {
    // `withBrand()` reuses the test's brand, so the second one is explicit —
    // as is its brand_id, since BelongsToBrand stamps from the ambient
    // context and both rows are being made in the same request.
    $first = $this->withBrand();
    BrandStorageSetting::factory()->create(['bucket' => 'first-bucket']);

    $second = Brand::factory()->create();
    BrandStorageSetting::factory()->create(['brand_id' => $second->getKey(), 'bucket' => 'second-bucket']);

    $resolver = app(DiskResolver::class);

    // The memo is keyed by the full disk name, and the resolver itself is
    // request-scoped — one brand's bucket can never answer for another's.
    expect($resolver->configFor('media:'.$first->getKey())['bucket'])->toBe('first-bucket')
        ->and($resolver->configFor('media:'.$second->getKey())['bucket'])->toBe('second-bucket');
});

/**
 * The operator console for per-brand storage (ADR-0029, ADR-0043 point 6),
 * mirroring the per-brand mail transport screen (ADR-0028).
 */
function storageSettingsAdmin(array $permissions = ['platform.access-admin', 'platform.manage-storage-settings']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.storage.test'])->save();

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

it('403s an admin without the storage capability and 404s on a brand host', function (): void {
    [, $admin] = storageSettingsAdmin(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.storage.test/admin/storage-settings')
        ->assertForbidden();

    $this->get('http://localhost/admin/storage-settings')->assertNotFound();
});

it('saves a brand\'s own storage and never echoes the secret back', function (): void {
    $brand = $this->withBrand();
    [, $admin] = storageSettingsAdmin();

    $this->actingAs($admin)
        ->put('http://admin.storage.test/admin/storage-settings/'.$brand->getKey(), [
            'useOwnStorage' => true,
            'bucket' => 'sxlan-media',
            'region' => 'garage',
            'endpoint' => 'http://garage:3900',
            'prefix' => 'media',
            'usePathStyleEndpoint' => true,
            'publicDirect' => false,
            'url' => null,
            'accessKeyId' => 'GKbrand',
            'secretAccessKey' => 'super-secret',
        ])
        ->assertRedirect();

    $settings = BrandStorageSetting::withoutBrandScope()->sole();
    expect($settings->bucket)->toBe('sxlan-media')
        ->and($settings->credentials['secret'])->toBe('super-secret');

    $this->actingAs($admin)
        ->get('http://admin.storage.test/admin/storage-settings/'.$brand->getKey().'/edit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/storage-settings/Edit')
            ->where('settings.accessKeyId', 'GKbrand')
            ->where('settings.hasSecretAccessKey', true)
            // The secret is not among the props at all.
            ->missing('settings.secretAccessKey'));
});

it('keeps the stored secret when the form submits none', function (): void {
    $brand = $this->withBrand();
    [, $admin] = storageSettingsAdmin();
    BrandStorageSetting::factory()->create(['bucket' => 'sxlan-media']);

    $this->actingAs($admin)
        ->put('http://admin.storage.test/admin/storage-settings/'.$brand->getKey(), [
            'useOwnStorage' => true,
            'bucket' => 'renamed-bucket',
            'prefix' => 'media',
            'usePathStyleEndpoint' => true,
            'publicDirect' => false,
            'accessKeyId' => 'GKtest',
            'secretAccessKey' => null,
        ])
        ->assertRedirect();

    $settings = BrandStorageSetting::withoutBrandScope()->sole();
    expect($settings->bucket)->toBe('renamed-bucket')
        ->and($settings->credentials['secret'])->toBe('secret');
});

it('refuses to switch own storage off while files still live in that bucket', function (): void {
    $brand = $this->withBrand();
    [, $admin] = storageSettingsAdmin();
    BrandStorageSetting::factory()->create(['bucket' => 'sxlan-media']);
    MediaItem::factory()->create(['disk' => 'media:'.$brand->getKey()]);

    // Clearing the settings would leave those rows naming a disk nobody can
    // build — unreadable files, not a tidy rollback.
    $this->actingAs($admin)
        ->put('http://admin.storage.test/admin/storage-settings/'.$brand->getKey(), [
            'useOwnStorage' => false,
        ])
        ->assertSessionHasErrors('useOwnStorage');

    expect(BrandStorageSetting::withoutBrandScope()->count())->toBe(1);
});

it('switches own storage off once the bucket is empty', function (): void {
    $brand = $this->withBrand();
    [, $admin] = storageSettingsAdmin();
    BrandStorageSetting::factory()->create(['bucket' => 'sxlan-media']);

    // Media that stayed on the deployment disk does not block the switch.
    MediaItem::factory()->create(['disk' => 'media']);

    $this->actingAs($admin)
        ->put('http://admin.storage.test/admin/storage-settings/'.$brand->getKey(), [
            'useOwnStorage' => false,
        ])
        ->assertRedirect();

    expect(BrandStorageSetting::withoutBrandScope()->count())->toBe(0)
        ->and(app(DiskResolver::class)->mediaDiskNameFor($brand->getKey()))->toBe('media');
});
