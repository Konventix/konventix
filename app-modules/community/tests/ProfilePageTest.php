<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationSubmitted;

/**
 * `GET /u/{username}` — the amended visibility gate (ADR-0052 supersedes the
 * original ADR-0051 §7 matrix, docs/decisions/0052-a-profile-chooses-its-audience.md):
 *
 * 1. The subject must have a profile AND a `CommunityMember` row on THIS
 *    brand, under every visibility — 404 otherwise.
 * 2. `internal` (the default) additionally requires a signed-in viewer with
 *    their own `CommunityMember` row on this brand.
 * 3. `event` additionally requires a shared CONFIRMED event participation
 *    (same event_id, both confirmed) on this brand.
 * 4. `public` has no viewer checks at all — guests render the page.
 *
 * Every denial is a 404 — never 403, never a login redirect (the route is
 * NOT in an auth group; whether a person is a member of a brand is itself
 * personal data, ADR-0051 §7).
 *
 * Rows are arranged by emitting Identity/Registration events, never by
 * inserting read-model rows directly — helpers here are prefixed
 * `communityPage*` since Pest helper functions are process-global.
 */
function communityPageProfileUpdatedFor(string $userId, string $username, string $visibility, ?string $tagline = null): UserProfileUpdated
{
    return new UserProfileUpdated(
        userId: $userId,
        email: $username.'@example.test',
        displayName: 'Ada Lovelace',
        username: $username,
        tagline: $tagline,
        statusEmoji: null,
        socialLinks: [],
        visibility: $visibility,
        locale: 'en',
        updatedAt: now()->toIso8601String(),
    );
}

function communityPageMembershipFor(string $userId, string $brandId): BrandMembershipProfileUpdated
{
    return new BrandMembershipProfileUpdated(
        userId: $userId,
        brandId: $brandId,
        avatarUrl: 'https://example.test/avatar.png',
        bannerUrl: 'https://example.test/banner.png',
        firstSeenAt: now()->subDays(3)->toIso8601String(),
        updatedAt: now()->toIso8601String(),
    );
}

/**
 * Registers the account (real `User` row for actingAs()), projects a
 * `CommunityProfile` at the given visibility, and — unless withMember is
 * false — projects a `CommunityMember` row on the given brand.
 */
function communityPageMakeSubject(string $brandId, string $username, string $visibility = 'internal', bool $withMember = true, ?string $tagline = null): User
{
    $user = User::factory()->create(['username' => $username]);

    event(new UserRegistered(
        userId: $user->getKey(),
        brandId: $brandId,
        email: $user->email,
        displayName: 'Ada Lovelace',
        username: $username,
        locale: 'en',
        registeredAt: now()->toIso8601String(),
    ));

    event(communityPageProfileUpdatedFor($user->getKey(), $username, $visibility, $tagline));

    if ($withMember) {
        event(communityPageMembershipFor($user->getKey(), $brandId));
    }

    return $user;
}

/**
 * A signed-in account with a `CommunityMember` row on the given brand — the
 * shape every `internal`/`event` viewer check needs.
 */
function communityPageMakeMember(string $brandId): User
{
    $user = User::factory()->create();

    event(communityPageMembershipFor($user->getKey(), $brandId));

    return $user;
}

function communityPageRegistrationSubmittedFor(string $registrationId, string $brandId, string $eventId, string $userId): RegistrationSubmitted
{
    return new RegistrationSubmitted(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        registrantUserId: $userId,
        settlement: 'free',
        confirmationGate: 'automatic',
        submittedAt: now()->toIso8601String(),
    );
}

function communityPageConfirmParticipation(string $brandId, string $eventId, string $userId): void
{
    $registrationId = (string) Str::ulid();

    event(communityPageRegistrationSubmittedFor($registrationId, $brandId, $eventId, $userId));

    event(new RegistrationConfirmed(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        registrantUserId: $userId,
        confirmedAt: now()->toIso8601String(),
        grants: [],
    ));
}

it('renders a member profile for a signed-in fellow member', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $expectedMemberSince = now()->subDays(3)->toDateString();

    communityPageMakeSubject($brand->getKey(), 'ada-lovelace');
    $viewer = communityPageMakeMember($brand->getKey());

    $this->actingAs($viewer)
        ->get('/u/ada-lovelace')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('community/Profile')
            ->where('profile.username', 'ada-lovelace')
            ->where('profile.displayName', 'Ada Lovelace')
            ->where('profile.memberSince', $expectedMemberSince)
            ->where('profile.avatarUrl', 'https://example.test/avatar.png')
            ->where('viewerIsMember', true));
});

it('404s for a guest', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    communityPageMakeSubject($brand->getKey(), 'ada-lovelace');

    $this->get('/u/ada-lovelace')->assertNotFound();
});

it('404s for a signed-in non-member of this brand', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    communityPageMakeSubject($brand->getKey(), 'ada-lovelace');
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get('/u/ada-lovelace')
        ->assertNotFound();
});

it('404s when the subject is not a member of this brand', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    communityPageMakeSubject($brand->getKey(), 'ada-lovelace', 'internal', withMember: false);
    $viewer = communityPageMakeMember($brand->getKey());

    $this->actingAs($viewer)
        ->get('/u/ada-lovelace')
        ->assertNotFound();
});

it('404s for an unknown username', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $viewer = communityPageMakeMember($brand->getKey());

    $this->actingAs($viewer)
        ->get('/u/nobody-here')
        ->assertNotFound();
});

it('404s when the community module is off', function (): void {
    $brand = $this->withBrand();

    $this->get('/u/ada-lovelace')->assertNotFound();
});

it('resolves the username case-insensitively', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    communityPageMakeSubject($brand->getKey(), 'ada-lovelace');
    $viewer = communityPageMakeMember($brand->getKey());

    $this->actingAs($viewer)
        ->get('/u/ADA-lovelace')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('community/Profile')
            ->where('profile.username', 'ada-lovelace'));
});

it('omits empty optional fields', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    communityPageMakeSubject($brand->getKey(), 'ada-lovelace', 'internal', withMember: true, tagline: null);
    $viewer = communityPageMakeMember($brand->getKey());

    $this->actingAs($viewer)
        ->get('/u/ada-lovelace')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('community/Profile')
            ->where('profile.tagline', null));
});

it('renders a public profile for a guest', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    communityPageMakeSubject($brand->getKey(), 'ada-lovelace', 'public');

    $this->get('/u/ada-lovelace')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('community/Profile')
            ->where('profile.username', 'ada-lovelace')
            ->where('viewerIsMember', false));
});

it('marks the viewer not a member on a public profile viewed by a guest, hiding comment eligibility', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    communityPageMakeSubject($brand->getKey(), 'ada-lovelace', 'public');

    $this->get('/u/ada-lovelace')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('viewerIsMember', false));
});

it('404s an event-only profile for a member with no shared confirmed event', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    communityPageMakeSubject($brand->getKey(), 'ada-lovelace', 'event');
    $viewer = communityPageMakeMember($brand->getKey());

    $this->actingAs($viewer)
        ->get('/u/ada-lovelace')
        ->assertNotFound();
});

it('renders an event-only profile when a shared confirmed participation exists on this brand', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $subject = communityPageMakeSubject($brand->getKey(), 'ada-lovelace', 'event');
    $viewer = communityPageMakeMember($brand->getKey());

    $eventId = (string) Str::ulid();
    communityPageConfirmParticipation($brand->getKey(), $eventId, $subject->getKey());
    communityPageConfirmParticipation($brand->getKey(), $eventId, $viewer->getKey());

    $this->actingAs($viewer)
        ->get('/u/ada-lovelace')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('community/Profile')
            ->where('profile.username', 'ada-lovelace'));
});

it('404s an event-only profile when the shared participation is unconfirmed', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $subject = communityPageMakeSubject($brand->getKey(), 'ada-lovelace', 'event');
    $viewer = communityPageMakeMember($brand->getKey());

    $eventId = (string) Str::ulid();
    // Subject confirmed, viewer only submitted (never confirmed).
    communityPageConfirmParticipation($brand->getKey(), $eventId, $subject->getKey());
    event(communityPageRegistrationSubmittedFor((string) Str::ulid(), $brand->getKey(), $eventId, $viewer->getKey()));

    $this->actingAs($viewer)
        ->get('/u/ada-lovelace')
        ->assertNotFound();
});
