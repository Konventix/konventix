<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\MediaUsage;

/**
 * A member's avatar and banner for the current brand (ADR-0051 §1): upload
 * through Platform's media library, point the membership at it, release the
 * previous usage on replace/remove, and announce the current state on
 * `BrandMembershipProfileUpdated` — including at first contact and on
 * replay, so "member since" is always replayable (ruling 2).
 */
it('stores an uploaded avatar in the brand library and points the membership at it', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $membership = BrandMembership::factory()->create(['user_id' => $user->getKey()]);
    Storage::fake('media');

    $this->actingAs($user)
        ->post('/settings/profile/avatar', [
            'file' => UploadedFile::fake()->image('face.png', 400, 400),
        ])
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
});

it('emits BrandMembershipProfileUpdated with both current urls', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $membership = BrandMembership::factory()->create(['user_id' => $user->getKey()]);
    Storage::fake('media');

    Event::fake([BrandMembershipProfileUpdated::class]);

    $this->actingAs($user)
        ->post('/settings/profile/avatar', [
            'file' => UploadedFile::fake()->image('face.png', 400, 400),
        ])
        ->assertRedirect();

    Event::assertDispatched(BrandMembershipProfileUpdated::class, fn (BrandMembershipProfileUpdated $event): bool => $event->userId === $user->getKey()
        && $event->brandId === $membership->brand_id
        && $event->avatarUrl !== null
        && $event->bannerUrl === null
        && $event->firstSeenAt === $membership->first_seen_at->toIso8601String());
});

it('releases the old usage when the avatar is replaced', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $membership = BrandMembership::factory()->create(['user_id' => $user->getKey()]);
    Storage::fake('media');

    $this->actingAs($user)->post('/settings/profile/avatar', [
        'file' => UploadedFile::fake()->image('first.png', 400, 400),
    ])->assertRedirect();

    $firstMediaId = $membership->refresh()->avatar_media_id;

    $this->actingAs($user)->post('/settings/profile/avatar', [
        'file' => UploadedFile::fake()->image('second.png', 400, 400),
    ])->assertRedirect();

    $membership->refresh();

    expect($membership->avatar_media_id)->not->toBe($firstMediaId);

    $oldUsageExists = MediaUsage::withoutBrandScope()
        ->where('media_item_id', $firstMediaId)
        ->where('module', 'identity')
        ->where('entity_type', 'membership')
        ->where('entity_id', $membership->getKey())
        ->exists();

    expect($oldUsageExists)->toBeFalse();
});

it('removes the avatar and releases its usage', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $membership = BrandMembership::factory()->create(['user_id' => $user->getKey()]);
    Storage::fake('media');

    $this->actingAs($user)->post('/settings/profile/avatar', [
        'file' => UploadedFile::fake()->image('face.png', 400, 400),
    ])->assertRedirect();

    $mediaId = $membership->refresh()->avatar_media_id;

    Event::fake([BrandMembershipProfileUpdated::class]);

    $this->actingAs($user)
        ->delete('/settings/profile/avatar')
        ->assertRedirect();

    $membership->refresh();

    expect($membership->avatar_media_id)->toBeNull();

    $usageExists = MediaUsage::withoutBrandScope()
        ->where('media_item_id', $mediaId)
        ->where('module', 'identity')
        ->where('entity_type', 'membership')
        ->where('entity_id', $membership->getKey())
        ->exists();

    expect($usageExists)->toBeFalse();

    Event::assertDispatched(BrandMembershipProfileUpdated::class, fn (BrandMembershipProfileUpdated $event): bool => $event->avatarUrl === null);
});

it('rejects a pdf', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    BrandMembership::factory()->create(['user_id' => $user->getKey()]);
    Storage::fake('media');

    $this->actingAs($user)
        ->post('/settings/profile/avatar', [
            'file' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ])
        ->assertInvalid(['file']);
});

it('emits the membership event when a member first arrives', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create(['password' => 'secret-password-1']);

    Event::fake([BrandMembershipProfileUpdated::class]);

    event(new Login('web', $user, false));

    $membership = BrandMembership::query()->where('user_id', $user->getKey())->firstOrFail();

    Event::assertDispatched(BrandMembershipProfileUpdated::class, fn (BrandMembershipProfileUpdated $event): bool => $event->userId === $user->getKey()
        && $event->brandId === $brand->getKey()
        && $event->avatarUrl === null
        && $event->bannerUrl === null
        && $event->firstSeenAt === $membership->first_seen_at->toIso8601String());
});

it('replays one membership event per membership', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    BrandMembership::factory()->create(['user_id' => $user->getKey()]);

    Event::fake([BrandMembershipProfileUpdated::class]);

    $this->artisan('identity:replay-profiles')->assertSuccessful();

    Event::assertDispatchedTimes(BrandMembershipProfileUpdated::class, 1);
    Event::assertDispatched(BrandMembershipProfileUpdated::class, fn (BrandMembershipProfileUpdated $event): bool => $event->userId === $user->getKey());
});
