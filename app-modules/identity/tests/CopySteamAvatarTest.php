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
        'avatar_url' => 'https://avatars.akamai.steamstatic.com/abc_full.jpg',
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
        'avatar_url' => 'https://avatars.steamstatic.com/missing_full.jpg',
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
        'avatar_url' => 'https://avatars.akamai.steamstatic.com/abc_full.jpg',
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

/**
 * #259 / audit N6: the fetch went out to whatever `avatar_url` said, with
 * redirects followed and no host check. Steam's Web API is the only writer of
 * that column today, so this is defence in depth rather than a live hole — but
 * it is a server-side request an authenticated user triggers on demand, and the
 * column is a plain string in a table a future import path could also write.
 */
it('refuses to fetch an avatar url that is not on a steam cdn host', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $membership = BrandMembership::factory()->create(['user_id' => $user->getKey()]);
    $user->socialIdentities()->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198012345678',
        'nickname' => 'ada',
        // The cloud metadata service: the canonical SSRF target, and one that
        // answers a plain GET.
        'avatar_url' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
        'linked_at' => now(),
    ]);
    Storage::fake('media');

    Http::fake();

    $this->actingAs($user)
        ->post('/settings/profile/avatar/steam')
        ->assertInvalid(['steam']);

    // The point of the criterion: not "the response was rejected", but that no
    // request left the process at all.
    Http::assertNothingSent();
    expect($membership->refresh()->avatar_media_id)->toBeNull();
});

it('refuses a host that merely ends in the allowlisted name', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    BrandMembership::factory()->create(['user_id' => $user->getKey()]);
    $user->socialIdentities()->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198012345678',
        'nickname' => 'ada',
        'avatar_url' => 'https://avatars.steamstatic.com.attacker.example/full.jpg',
        'linked_at' => now(),
    ]);
    Storage::fake('media');

    Http::fake();

    $this->actingAs($user)
        ->post('/settings/profile/avatar/steam')
        ->assertInvalid(['steam']);

    Http::assertNothingSent();
});

/**
 * The neighbouring upload path caps an avatar at 5120 KB
 * (`MembershipImageController::storeAvatar()`); a body arriving over HTTP has
 * no `max:` rule in front of it, so the ceiling has to be restated here or the
 * two paths disagree about what an avatar may weigh.
 */
it('refuses an avatar body larger than the upload path would accept', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $membership = BrandMembership::factory()->create(['user_id' => $user->getKey()]);
    $user->socialIdentities()->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198012345678',
        'nickname' => 'ada',
        'avatar_url' => 'https://avatars.steamstatic.com/huge_full.jpg',
        'linked_at' => now(),
    ]);
    Storage::fake('media');

    Http::fake([
        '*' => Http::response(str_repeat('a', 5120 * 1024 + 1), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->actingAs($user)
        ->post('/settings/profile/avatar/steam')
        ->assertInvalid(['steam']);

    expect($membership->refresh()->avatar_media_id)->toBeNull();
});
