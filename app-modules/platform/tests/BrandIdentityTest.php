<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\DeleteMediaItem;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\MediaUsage;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A brand's visual identity: the mark in the topbar and the icon in the
 * browser tab, both media-library references (ADR-0035) resolved per
 * request. There is exactly one fallback level — the bundled artwork — and
 * every level of this is exercised here, because "the brand uploaded
 * nothing" is the normal state rather than an edge case.
 */
function brandIdentityAdmin(string $host): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => $host])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    $permissions = ['platform.access-admin', 'platform.view-brands', 'platform.manage-brands'];

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Identity Editor',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

it('shares the brand\'s own name and marks with every page', function (): void {
    $brand = $this->withBrand();
    $brand->update(['name' => 'SXLan']);

    $logo = MediaItem::factory()->create(['alt_text' => 'The SXLan mark']);
    $dark = MediaItem::factory()->create();

    $brand->update([
        'logo_media_id' => $logo->getKey(),
        'logo_dark_media_id' => $dark->getKey(),
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // Never config('app.name') on a brand host — the deployment's
            // application name is nobody's public identity (ADR-0001).
            ->where('name', 'SXLan')
            ->where('brand.name', 'SXLan')
            // Asserted through the library rather than a hand-built URL:
            // whether a disk is served directly or proxied is deployment
            // configuration (ADR-0043), and the shell just renders whatever
            // the library says.
            ->where('brand.logoUrl', app(MediaLibrary::class)->urlFor($logo->getKey()))
            ->where('brand.logoDarkUrl', app(MediaLibrary::class)->urlFor($dark->getKey()))
            ->where('brand.logoAlt', 'The SXLan mark'));
});

it('leaves every asset null for a brand that has uploaded nothing', function (): void {
    $this->withBrand();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('brand.logoUrl', null)
            ->where('brand.logoDarkUrl', null)
            ->where('brand.faviconUrl', null));
});

it('puts the brand\'s favicon and name in the document head', function (): void {
    $brand = $this->withBrand();
    $brand->update(['name' => 'SXLan']);

    $favicon = MediaItem::factory()->create(['mime_type' => 'image/svg+xml']);
    $brand->update(['favicon_media_id' => $favicon->getKey()]);

    $url = app(MediaLibrary::class)->urlFor($favicon->getKey());

    $this->get('/')
        ->assertOk()
        ->assertSee('<link rel="icon" href="'.$url.'">', escape: false)
        // The title itself is rendered by the SSR bundle from the shared
        // `name` prop (see resources/js/app.ts), which the first test in
        // this file covers; what is asserted here is the head element the
        // root template owns.
        ->assertSee('<link rel="apple-touch-icon" href="'.$url.'">', escape: false);
});

it('falls back to the bundled icons when the brand has no favicon', function (): void {
    $this->withBrand();

    $this->get('/')
        ->assertOk()
        ->assertSee('<link rel="icon" href="/favicon.ico" sizes="any">', escape: false);
});

it('wears the operator\'s name and no brand assets on the admin host', function (): void {
    [$operator, $admin] = brandIdentityAdmin('admin.identity.test');

    $this->actingAs($admin)
        ->get('http://admin.identity.test/admin/brands')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('name', $operator->name)
            ->where('brand.logoUrl', null)
            ->where('brand.faviconUrl', null));
});

it('offers the brand\'s own images to the asset pickers', function (): void {
    [, $admin] = brandIdentityAdmin('admin.pickers.test');

    $brand = $this->withBrand();
    $mine = MediaItem::factory()->create();
    MediaItem::factory()->create(['mime_type' => 'application/pdf']);

    $other = Brand::factory()->create(['slug' => 'other-identity']);
    app(BrandContext::class)->set($other);
    $theirs = MediaItem::factory()->create();

    $this->actingAs($admin)
        ->get("http://admin.pickers.test/admin/brands/{$brand->getKey()}/edit")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($mine, $theirs): void {
            $ids = collect($page->toArray()['props']['media'])->pluck('id')->all();

            // Images belonging to this brand only: never another brand's
            // file, and never a PDF offered as a logo.
            expect($ids)->toBe([$mine->getKey()])
                ->and($ids)->not->toContain($theirs->getKey());
        });
});

it('saves the chosen marks', function (): void {
    [, $admin] = brandIdentityAdmin('admin.saveassets.test');

    $brand = $this->withBrand();
    $brand->domains()->create(['host' => 'saveassets.test', 'is_primary' => true]);
    $logo = MediaItem::factory()->create();
    $favicon = MediaItem::factory()->create();

    $this->actingAs($admin)
        ->put("http://admin.saveassets.test/admin/brands/{$brand->getKey()}", [
            'name' => $brand->name,
            'slug' => $brand->slug,
            'currency' => 'EUR',
            'hosts' => ['saveassets.test'],
            'logoMediaId' => $logo->getKey(),
            'faviconMediaId' => $favicon->getKey(),
        ])
        ->assertRedirect();

    $brand->refresh();

    expect($brand->logo_media_id)->toBe($logo->getKey())
        ->and($brand->favicon_media_id)->toBe($favicon->getKey())
        ->and($brand->logo_dark_media_id)->toBeNull();
});

it('refuses a mark belonging to a different brand', function (): void {
    [, $admin] = brandIdentityAdmin('admin.stolenassets.test');

    $brand = $this->withBrand();
    $brand->domains()->create(['host' => 'stolenassets.test', 'is_primary' => true]);

    $other = Brand::factory()->create(['slug' => 'owner-of-the-mark']);
    app(BrandContext::class)->set($other);
    $theirs = MediaItem::factory()->create();

    $this->actingAs($admin)
        ->from("http://admin.stolenassets.test/admin/brands/{$brand->getKey()}/edit")
        ->put("http://admin.stolenassets.test/admin/brands/{$brand->getKey()}", [
            'name' => $brand->name,
            'slug' => $brand->slug,
            'currency' => 'EUR',
            'hosts' => ['stolenassets.test'],
            'logoMediaId' => $theirs->getKey(),
        ])
        ->assertSessionHasErrors('logoMediaId');

    expect($brand->refresh()->logo_media_id)->toBeNull();
});

it('registers its marks as usages so the library refuses to delete them', function (): void {
    [, $admin] = brandIdentityAdmin('admin.usedassets.test');

    $brand = $this->withBrand();
    $brand->domains()->create(['host' => 'usedassets.test', 'is_primary' => true]);
    $logo = MediaItem::factory()->create();

    $this->actingAs($admin)
        ->put("http://admin.usedassets.test/admin/brands/{$brand->getKey()}", [
            'name' => $brand->name,
            'slug' => $brand->slug,
            'currency' => 'EUR',
            'hosts' => ['usedassets.test'],
            'logoMediaId' => $logo->getKey(),
        ])
        ->assertRedirect();

    expect(MediaUsage::withoutBrandScope()->where('media_item_id', $logo->getKey())->get())
        ->toHaveCount(1)
        ->first()->module->toBe('platform')
        ->first()->entity_type->toBe('brand')
        ->first()->entity_id->toBe($brand->getKey());

    // The reference is what stands between the media admin and the file:
    // without it the delete succeeds and every page wears a broken image.
    expect(fn () => app(DeleteMediaItem::class)->handle($logo))
        ->toThrow(ValidationException::class);
});

it('releases a mark it no longer wears and keeps one it still does', function (): void {
    [, $admin] = brandIdentityAdmin('admin.swapassets.test');

    $brand = $this->withBrand();
    $brand->domains()->create(['host' => 'swapassets.test', 'is_primary' => true]);
    $shared = MediaItem::factory()->create();
    $replacement = MediaItem::factory()->create();

    $payload = fn (array $marks): array => [
        'name' => $brand->name,
        'slug' => $brand->slug,
        'currency' => 'EUR',
        'hosts' => ['swapassets.test'],
        ...$marks,
    ];

    $usagesOf = fn (MediaItem $item): int => MediaUsage::withoutBrandScope()
        ->where('media_item_id', $item->getKey())
        ->count();

    // One file wearing two hats: the usage is per item, not per field.
    $this->actingAs($admin)
        ->put("http://admin.swapassets.test/admin/brands/{$brand->getKey()}", $payload([
            'logoMediaId' => $shared->getKey(),
            'faviconMediaId' => $shared->getKey(),
        ]))
        ->assertRedirect();

    expect($usagesOf($shared))->toBe(1);

    // Swapping the logo out must not release a file the favicon still uses.
    $this->actingAs($admin)
        ->put("http://admin.swapassets.test/admin/brands/{$brand->getKey()}", $payload([
            'logoMediaId' => $replacement->getKey(),
            'faviconMediaId' => $shared->getKey(),
        ]))
        ->assertRedirect();

    expect($usagesOf($shared))->toBe(1)
        ->and($usagesOf($replacement))->toBe(1);

    // Dropping the last reference releases it.
    $this->actingAs($admin)
        ->put("http://admin.swapassets.test/admin/brands/{$brand->getKey()}", $payload([
            'logoMediaId' => $replacement->getKey(),
        ]))
        ->assertRedirect();

    expect($usagesOf($shared))->toBe(0)
        ->and($usagesOf($replacement))->toBe(1);
});
