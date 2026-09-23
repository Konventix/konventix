<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\MediaItem;

/**
 * The shared `auth.user` prop keeps Identity's promise (ADR-0051 §5): ULID,
 * display name, avatar URL, locale, and since #243 the zone every timestamp
 * on screen is rendered in — never the whole model.
 */
it('shares exactly the five promised fields', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.id', $user->getKey())
        ->where('auth.user.name', $user->name)
        // The person's own choice, not the effective locale: a fresh
        // account has none and follows the brand (ADR-0068 §4).
        ->where('auth.user.locale', null)
        ->where('auth.user.avatarUrl', null)
        ->where('auth.user.timezone', $user->timezone)
        ->missing('auth.user.email'));
});

it('resolves the avatar from the current brand membership', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    $media = MediaItem::factory()->create();
    BrandMembership::factory()->create([
        'user_id' => $user->getKey(),
        'brand_id' => $brand->getKey(),
        'avatar_media_id' => $media->getKey(),
    ]);

    $this->actingAs($user)->get('/settings')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.avatarUrl', fn (?string $url): bool => $url !== null));
});

it('stays null for guests', function (): void {
    $this->withBrand();

    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user', null));
});
