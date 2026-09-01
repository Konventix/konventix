<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Community\Models\CommunityMember;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;

/**
 * Community's read models (SPEC.md "Owns" — community profile), projected
 * from Identity's account and brand-membership events (ADR-0004). Rows exist
 * only through the projection, so every case emits the Identity event
 * instead of inventing a row (Author-model / RecipientReadModelTest
 * precedent, `app-modules/content`, `app-modules/messaging`).
 */
function communityRegisteredEventFor(string $userId, string $brandId): UserRegistered
{
    return new UserRegistered(
        userId: $userId,
        brandId: $brandId,
        email: 'ada@example.test',
        displayName: 'Ada Lovelace',
        username: 'ada-lovelace',
        locale: 'en',
        registeredAt: now()->toIso8601String(),
    );
}

function communityProfileUpdatedEventFor(string $userId): UserProfileUpdated
{
    return new UserProfileUpdated(
        userId: $userId,
        email: 'countess@example.test',
        displayName: 'Countess of Lovelace',
        username: 'countess-lovelace',
        tagline: 'Enchantress of numbers',
        statusEmoji: '💡',
        socialLinks: ['mastodon' => 'https://example.test/@ada'],
        locale: 'en',
        updatedAt: now()->toIso8601String(),
    );
}

function communityMembershipUpdatedEventFor(string $userId, string $brandId, ?string $avatarUrl = 'https://example.test/avatar.png'): BrandMembershipProfileUpdated
{
    return new BrandMembershipProfileUpdated(
        userId: $userId,
        brandId: $brandId,
        avatarUrl: $avatarUrl,
        bannerUrl: 'https://example.test/banner.png',
        firstSeenAt: now()->subDays(3)->toIso8601String(),
        updatedAt: now()->toIso8601String(),
    );
}

it('creates a profile row from UserRegistered with username and display name', function (): void {
    $brand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(communityRegisteredEventFor($userId, $brand->getKey()));

    $profile = CommunityProfile::query()->findOrFail($userId);

    expect($profile->username)->toBe('ada-lovelace')
        ->and($profile->display_name)->toBe('Ada Lovelace');
});

it('upserts all five profile fields from UserProfileUpdated', function (): void {
    $brand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(communityRegisteredEventFor($userId, $brand->getKey()));
    event(communityProfileUpdatedEventFor($userId));

    $profile = CommunityProfile::query()->findOrFail($userId);

    expect($profile->username)->toBe('countess-lovelace')
        ->and($profile->display_name)->toBe('Countess of Lovelace')
        ->and($profile->tagline)->toBe('Enchantress of numbers')
        ->and($profile->status_emoji)->toBe('💡')
        ->and($profile->social_links)->toBe(['mastodon' => 'https://example.test/@ada']);
});

it('stores null social links when the event carries an empty array', function (): void {
    $brand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(communityRegisteredEventFor($userId, $brand->getKey()));
    event(new UserProfileUpdated(
        userId: $userId,
        email: 'ada@example.test',
        displayName: 'Ada Lovelace',
        username: 'ada-lovelace',
        tagline: null,
        statusEmoji: null,
        socialLinks: [],
        locale: 'en',
        updatedAt: now()->toIso8601String(),
    ));

    $profile = CommunityProfile::query()->findOrFail($userId);

    expect($profile->social_links)->toBeNull();
});

it('converges to one row when UserRegistered is replayed', function (): void {
    $brand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(communityRegisteredEventFor($userId, $brand->getKey()));
    event(communityRegisteredEventFor($userId, $brand->getKey()));

    expect(CommunityProfile::query()->count())->toBe(1);
});

it('creates a member row from BrandMembershipProfileUpdated only when the module is active for that brand', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);
    $userId = (string) Str::ulid();

    event(communityMembershipUpdatedEventFor($userId, $brand->getKey()));

    $member = CommunityMember::withoutBrandScope()->where('user_id', $userId)->firstOrFail();

    expect($member->brand_id)->toBe($brand->getKey())
        ->and($member->avatar_url)->toBe('https://example.test/avatar.png')
        ->and($member->banner_url)->toBe('https://example.test/banner.png');
});

it('short-circuits silently when the module is off for the event brand', function (): void {
    $brand = $this->withBrand();
    $userId = (string) Str::ulid();

    event(communityMembershipUpdatedEventFor($userId, $brand->getKey()));

    expect(CommunityMember::withoutBrandScope()->where('user_id', $userId)->exists())->toBeFalse();
});

it('updates the avatar and banner URLs in place on a second membership event', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);
    $userId = (string) Str::ulid();

    event(communityMembershipUpdatedEventFor($userId, $brand->getKey(), 'https://example.test/avatar.png'));
    event(communityMembershipUpdatedEventFor($userId, $brand->getKey(), 'https://example.test/new-avatar.png'));

    expect(CommunityMember::withoutBrandScope()->where('user_id', $userId)->count())->toBe(1);

    $member = CommunityMember::withoutBrandScope()->where('user_id', $userId)->firstOrFail();

    expect($member->avatar_url)->toBe('https://example.test/new-avatar.png');
});

it('rebuilds both read models end-to-end via identity:replay-profiles', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $user = User::factory()->create(['name' => 'Ada Lovelace']);
    BrandMembership::factory()->for($brand)->create(['user_id' => $user->getKey()]);

    CommunityProfile::query()->whereKey($user->getKey())->delete();
    CommunityMember::withoutBrandScope()->where('user_id', $user->getKey())->delete();

    $this->artisan('identity:replay-profiles')->assertSuccessful();

    expect(CommunityProfile::query()->findOrFail($user->getKey())->display_name)->toBe('Ada Lovelace')
        ->and(CommunityMember::withoutBrandScope()->where('user_id', $user->getKey())->where('brand_id', $brand->getKey())->exists())->toBeTrue();
});
