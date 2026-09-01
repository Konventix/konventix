<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\MediaUsage;

/**
 * Copying a linked Steam avatar into the brand's media library and pointing
 * the membership at it (Task 5) — reuses `UpdateMembershipImage::pointAt()`
 * rather than duplicating the usage bookkeeping (ADR-0051 §1).
 */
function steamAvatarPngBytes(): string
{
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

it('copies the steam avatar into the brand library and points the membership at it', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $membership = BrandMembership::factory()->create(['user_id' => $user->getKey()]);
    $user->socialIdentities()->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198012345678',
        'nickname' => 'ada',
        'avatar_url' => 'https://steamcdn.example.com/avatar.png',
        'linked_at' => now(),
    ]);
    Storage::fake('media');

    Http::fake([
        '*' => Http::response(steamAvatarPngBytes(), 200, ['Content-Type' => 'image/png']),
    ]);

    Event::fake([BrandMembershipProfileUpdated::class]);

    $this->actingAs($user)
        ->post('/settings/profile/avatar/steam')
        ->assertRedirect();

    $membership->refresh();

    expect($membership->avatar_media_id)->not->toBeNull();

    $usageExists = MediaUsage::withoutBrandScope()
        ->where('media_item_id', $membership->avatar_media_id)
        ->where('module', 'identity')
        ->where('entity_type', 'membership')
        ->where('entity_id', $membership->getKey())
        ->exists();

    expect($usageExists)->toBeTrue();

    $item = MediaItem::withoutBrandScope()->findOrFail($membership->avatar_media_id);
    Storage::disk('media')->assertExists($item->path);

    Event::assertDispatched(BrandMembershipProfileUpdated::class, fn (BrandMembershipProfileUpdated $event): bool => $event->userId === $user->getKey()
        && $event->brandId === $membership->brand_id
        && $event->avatarUrl !== null);
});

it('rejects the copy when no steam identity is linked', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $membership = BrandMembership::factory()->create(['user_id' => $user->getKey()]);
    Storage::fake('media');

    Http::fake();

    $this->actingAs($user)
        ->post('/settings/profile/avatar/steam')
        ->assertInvalid(['steam']);

    expect($membership->refresh()->avatar_media_id)->toBeNull();
    Http::assertNothingSent();
});

it('rejects the copy when the linked steam identity has no avatar url', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $membership = BrandMembership::factory()->create(['user_id' => $user->getKey()]);
    $user->socialIdentities()->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198012345678',
        'nickname' => 'ada',
        'avatar_url' => null,
        'linked_at' => now(),
    ]);
    Storage::fake('media');

    Http::fake();

    $this->actingAs($user)
        ->post('/settings/profile/avatar/steam')
        ->assertInvalid(['steam']);

    expect($membership->refresh()->avatar_media_id)->toBeNull();
    Http::assertNothingSent();
});

it('rejects the copy when the upstream avatar fetch 404s and leaves the membership untouched', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $membership = BrandMembership::factory()->create(['user_id' => $user->getKey()]);
    $user->socialIdentities()->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198012345678',
        'nickname' => 'ada',
        'avatar_url' => 'https://steamcdn.example.com/missing.png',
        'linked_at' => now(),
    ]);
    Storage::fake('media');

    Http::fake([
        '*' => Http::response('not found', 404),
    ]);

    $this->actingAs($user)
        ->post('/settings/profile/avatar/steam')
        ->assertInvalid(['steam']);

    expect($membership->refresh()->avatar_media_id)->toBeNull();
});

it('rejects the copy when the user has no membership on the current brand', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $user->socialIdentities()->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198012345678',
        'nickname' => 'ada',
        'avatar_url' => 'https://steamcdn.example.com/avatar.png',
        'linked_at' => now(),
    ]);
    Storage::fake('media');

    Http::fake([
        '*' => Http::response(steamAvatarPngBytes(), 200, ['Content-Type' => 'image/png']),
    ]);

    // UpdateMembershipImage::membershipFor() throws keyed 'file' — every
    // failure of the Steam flow must surface keyed 'steam' instead, since
    // the user clicked a Steam button, not a file input (see
    // CopySteamAvatar::handle()'s ValidationException remap).
    $this->actingAs($user)
        ->post('/settings/profile/avatar/steam')
        ->assertInvalid(['steam']);
});
