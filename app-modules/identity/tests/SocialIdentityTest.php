<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Identity\Gdpr\IdentityGdprHandler;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\MediaUsage;

/**
 * The table behind social login, and the one change it forces on password
 * authentication.
 *
 * Both unique constraints exist to stop a class of account confusion rather
 * than to tidy the data: one provider identity must not back two logins, and
 * one account must not accumulate several identities at the same provider.
 */
it('refuses to let one provider identity back two accounts', function (): void {
    SocialIdentity::factory()->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000001',
    ]);

    SocialIdentity::factory()->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000001',
    ]);
})->throws(QueryException::class);

it('refuses a second identity at the same provider for one account', function (): void {
    $user = User::factory()->create();

    SocialIdentity::factory()->for($user)->create(['provider' => 'steam']);
    SocialIdentity::factory()->for($user)->create(['provider' => 'steam']);
})->throws(QueryException::class);

/**
 * A Steam-first account genuinely has no password (decision: the column is
 * nullable rather than filled with a placeholder). The password login path
 * must refuse it outright — a null hash must never be coerced into something
 * `Hash::check` will compare.
 */
it('never authenticates a passwordless account through the password path', function (): void {
    $user = User::factory()->create(['password' => null]);

    expect($user->hasPassword())->toBeFalse();

    $this->post('/login', [
        'email' => $user->email,
        'password' => '',
    ])->assertSessionHasErrors();

    expect(auth()->check())->toBeFalse();
});

it('still authenticates an account that does have a password', function (): void {
    $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

    expect($user->hasPassword())->toBeTrue();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-horse',
    ]);

    expect(auth()->id())->toBe($user->getKey());
});

/**
 * Slice 15a's promise in Identity's TODO: "Social identities join the export
 * when social login lands."
 */
it('exports linked identities and erases them on anonymization', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    SocialIdentity::factory()->for($user)->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000123',
        'nickname' => 'gaben',
    ]);

    $handler = app(IdentityGdprHandler::class);
    $subject = SubjectRef::forUser((string) $user->getKey());

    $export = $handler->exportFor($subject);

    expect($export['social_identities'])->toHaveCount(1)
        ->and($export['social_identities'][0]['provider_user_id'])->toBe('76561198000000123');

    $handler->delete($subject, DeletionMode::Anonymize);

    // Gone entirely, not tombstoned: the row is nothing but an identifier for
    // the person who asked to be forgotten, and leaving it would keep the
    // Steam login working against an anonymized account.
    expect(SocialIdentity::query()->where('user_id', $user->getKey())->exists())->toBeFalse();
});

/**
 * Task 10: username/tagline/status_emoji/social_links join the export and
 * the tombstone, alongside profile_visibility (already covered elsewhere —
 * Task 13's Identity export/tombstone additions).
 */
it('exports the four account-shape fields and tombstones them on anonymization', function (): void {
    $this->withBrand();

    $user = User::factory()->create([
        'username' => 'ada-lovelace',
        'tagline' => 'Enchantress of numbers',
        'status_emoji' => '💡',
        'social_links' => ['mastodon' => 'https://example.test/@ada'],
    ]);

    $handler = app(IdentityGdprHandler::class);
    $subject = SubjectRef::forUser((string) $user->getKey());

    $export = $handler->exportFor($subject);

    expect($export['account']['username'])->toBe('ada-lovelace')
        ->and($export['account']['tagline'])->toBe('Enchantress of numbers')
        ->and($export['account']['status_emoji'])->toBe('💡')
        ->and($export['account']['social_links'])->toBe(['mastodon' => 'https://example.test/@ada']);

    $handler->delete($subject, DeletionMode::Anonymize);

    $user->refresh();

    expect($user->username)->toBe('deleted-'.strtolower((string) $user->getKey()))
        ->and($user->tagline)->toBeNull()
        ->and($user->status_emoji)->toBeNull()
        ->and($user->social_links)->toBeNull();
});

/**
 * Task 10: the memberships map gains avatar_media_id/banner_media_id, and a
 * deletion releases the media usage those columns recorded before the
 * membership row itself is removed — a stale MediaUsage would otherwise pin
 * the image forever, referencing a membership that no longer exists.
 */
it('exports membership avatar/banner media ids and releases their usage on deletion', function (): void {
    $brand = $this->withBrand();

    $user = User::factory()->create();

    $avatar = MediaItem::factory()->create(['brand_id' => $brand->getKey()]);
    $banner = MediaItem::factory()->create(['brand_id' => $brand->getKey()]);

    $membership = BrandMembership::factory()->create([
        'user_id' => $user->getKey(),
        'avatar_media_id' => $avatar->getKey(),
        'banner_media_id' => $banner->getKey(),
    ]);

    app(MediaLibrary::class)->recordUsage($avatar->getKey(), 'identity', 'membership', $membership->getKey());
    app(MediaLibrary::class)->recordUsage($banner->getKey(), 'identity', 'membership', $membership->getKey());

    $handler = app(IdentityGdprHandler::class);
    $subject = SubjectRef::forUser((string) $user->getKey());

    $export = $handler->exportFor($subject);

    expect($export['brand_memberships'][0]['avatar_media_id'])->toBe($avatar->getKey())
        ->and($export['brand_memberships'][0]['banner_media_id'])->toBe($banner->getKey());

    $handler->delete($subject, DeletionMode::Anonymize);

    expect(MediaUsage::withoutBrandScope()->where('media_item_id', $avatar->getKey())->exists())->toBeFalse()
        ->and(MediaUsage::withoutBrandScope()->where('media_item_id', $banner->getKey())->exists())->toBeFalse();
});
