<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Community\Models\CommunityComment;
use Konventix\Community\Models\CommunityReaction;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;

/**
 * POST/DELETE `/u/{username}/comments[...]` — Task 9's profile-wall
 * comments and reactions.
 *
 * Comments stay internal-scope under every visibility (ADR-0052 §2): every
 * endpoint resolves through `resolveProfileForMemberOrFail`, so a guest or a
 * signed-in non-member gets the same 404 `ProfileController::show` gives —
 * even on a `public` profile, where the page itself would otherwise render
 * for them.
 *
 * No moderation/report flow (ruled) — deletion is author-or-wall-owner
 * only, and a third party gets a 403 rather than a 404, because the page
 * they are acting from already conceded the comment's existence by
 * rendering it (`MyRegistrationsController::show`'s ownership-403
 * precedent).
 *
 * Rows are arranged by emitting Identity events, never by inserting read-
 * model rows directly — helpers here are prefixed `communityWall*` (distinct
 * from `ProfilePageTest`'s `communityPage*`) since Pest helper functions are
 * process-global.
 */
function communityWallProfileUpdatedFor(string $userId, string $username, string $visibility = 'internal'): UserProfileUpdated
{
    return new UserProfileUpdated(
        userId: $userId,
        email: $username.'@example.test',
        displayName: 'Ada Lovelace',
        username: $username,
        tagline: null,
        statusEmoji: null,
        socialLinks: [],
        visibility: $visibility,
        listedInAttendees: false,
        locale: 'en',
        updatedAt: now()->toIso8601String(),
    );
}

function communityWallMembershipFor(string $userId, string $brandId): BrandMembershipProfileUpdated
{
    return new BrandMembershipProfileUpdated(
        userId: $userId,
        brandId: $brandId,
        avatarUrl: null,
        bannerUrl: null,
        firstSeenAt: now()->subDays(3)->toIso8601String(),
        updatedAt: now()->toIso8601String(),
    );
}

/**
 * Registers the account (real `User` row for actingAs()), projects a
 * `CommunityProfile` at the given visibility, and projects a
 * `CommunityMember` row on the given brand — a fully resolvable member, so
 * a comment they post has an `authorUsername` the page can render.
 */
function communityWallMakeAccount(string $brandId, ?string $username = null, string $visibility = 'internal'): User
{
    $user = $username === null ? User::factory()->create() : User::factory()->create(['username' => $username]);
    $username ??= $user->username;

    event(new UserRegistered(
        userId: $user->getKey(),
        brandId: $brandId,
        email: $user->email,
        displayName: 'Ada Lovelace',
        username: $username,
        locale: 'en',
        registeredAt: now()->toIso8601String(),
    ));

    event(communityWallProfileUpdatedFor($user->getKey(), $username, $visibility));
    event(communityWallMembershipFor($user->getKey(), $brandId));

    return $user;
}

/**
 * The wall's subject, at the given visibility.
 */
function communityWallMakeSubject(string $brandId, string $username, string $visibility = 'internal'): User
{
    return communityWallMakeAccount($brandId, $username, $visibility);
}

/**
 * A signed-in, fully-projected member of the given brand — the shape every
 * internal-scope check needs, and a resolvable author for their comments.
 */
function communityWallMakeMember(string $brandId): User
{
    return communityWallMakeAccount($brandId);
}

it('lets a member comment on a profile', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $subject = communityWallMakeSubject($brand->getKey(), 'ada-lovelace');
    $viewer = communityWallMakeMember($brand->getKey());

    $this->actingAs($viewer)
        ->post('/u/ada-lovelace/comments', ['body' => 'Welcome to the wall!'])
        ->assertRedirect();

    $comment = CommunityComment::query()->sole();
    expect($comment->module)->toBe(CommunityComment::MODULE)
        ->and($comment->entity_type)->toBe(CommunityComment::ENTITY_PROFILE)
        ->and($comment->entity_id)->toBe($subject->getKey())
        ->and($comment->author_user_id)->toBe((string) $viewer->getKey())
        ->and($comment->body)->toBe('Welcome to the wall!');

    $this->actingAs($viewer)
        ->get('/u/ada-lovelace')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('comments.0.body', 'Welcome to the wall!')
            ->where('comments.0.authorUsername', $viewer->username)
            ->where('comments.0.isMine', true));
});

it('404s a comment from a non-member', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    communityWallMakeSubject($brand->getKey(), 'ada-lovelace');
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post('/u/ada-lovelace/comments', ['body' => 'Hello?'])
        ->assertNotFound();

    expect(CommunityComment::query()->count())->toBe(0);
});

it('404s comments for a guest on a public profile', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    communityWallMakeSubject($brand->getKey(), 'ada-lovelace', 'public');

    $this->get('/u/ada-lovelace')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('comments', null)
            ->where('canComment', false));

    $this->post('/u/ada-lovelace/comments', ['body' => 'Hello?'])
        ->assertNotFound();

    expect(CommunityComment::query()->count())->toBe(0);
});

it('404s comments for a signed-in non-member on a public profile', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    communityWallMakeSubject($brand->getKey(), 'ada-lovelace', 'public');
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get('/u/ada-lovelace')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('comments', null)
            ->where('canComment', false));

    $this->actingAs($stranger)
        ->post('/u/ada-lovelace/comments', ['body' => 'Hello?'])
        ->assertNotFound();

    expect(CommunityComment::query()->count())->toBe(0);
});

it('caps the body at 2000 characters', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    communityWallMakeSubject($brand->getKey(), 'ada-lovelace');
    $viewer = communityWallMakeMember($brand->getKey());

    $this->actingAs($viewer)
        ->post('/u/ada-lovelace/comments', ['body' => str_repeat('x', 2001)])
        ->assertInvalid(['body']);

    expect(CommunityComment::query()->count())->toBe(0);
});

it('lets the author delete their comment', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $subject = communityWallMakeSubject($brand->getKey(), 'ada-lovelace');
    $viewer = communityWallMakeMember($brand->getKey());

    $comment = CommunityComment::factory()->create([
        'entity_id' => $subject->getKey(),
        'author_user_id' => $viewer->getKey(),
    ]);

    $this->actingAs($viewer)
        ->delete("/u/ada-lovelace/comments/{$comment->getKey()}")
        ->assertRedirect();

    expect(CommunityComment::query()->count())->toBe(0);
});

it('lets the wall owner delete any comment on their wall', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $subject = communityWallMakeSubject($brand->getKey(), 'ada-lovelace');
    $commenter = communityWallMakeMember($brand->getKey());

    $comment = CommunityComment::factory()->create([
        'entity_id' => $subject->getKey(),
        'author_user_id' => $commenter->getKey(),
    ]);

    $this->actingAs($subject)
        ->delete("/u/ada-lovelace/comments/{$comment->getKey()}")
        ->assertRedirect();

    expect(CommunityComment::query()->count())->toBe(0);
});

it('403s a third party deleting someone else\'s comment', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $subject = communityWallMakeSubject($brand->getKey(), 'ada-lovelace');
    $commenter = communityWallMakeMember($brand->getKey());
    $thirdParty = communityWallMakeMember($brand->getKey());

    $comment = CommunityComment::factory()->create([
        'entity_id' => $subject->getKey(),
        'author_user_id' => $commenter->getKey(),
    ]);

    $this->actingAs($thirdParty)
        ->delete("/u/ada-lovelace/comments/{$comment->getKey()}")
        ->assertForbidden();

    expect(CommunityComment::query()->count())->toBe(1);
});

it('reacts once and recounts', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $subject = communityWallMakeSubject($brand->getKey(), 'ada-lovelace');
    $commenter = communityWallMakeMember($brand->getKey());
    $reactor = communityWallMakeMember($brand->getKey());

    $comment = CommunityComment::factory()->create([
        'entity_id' => $subject->getKey(),
        'author_user_id' => $commenter->getKey(),
    ]);

    $this->actingAs($reactor)
        ->post("/u/ada-lovelace/comments/{$comment->getKey()}/react")
        ->assertRedirect();

    $this->actingAs($reactor)
        ->post("/u/ada-lovelace/comments/{$comment->getKey()}/react")
        ->assertRedirect();

    expect(CommunityReaction::query()->count())->toBe(1)
        ->and($comment->refresh()->reaction_count)->toBe(1);

    $this->actingAs($reactor)
        ->get('/u/ada-lovelace')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('comments.0.reactionCount', 1)
            ->where('comments.0.hasReacted', true));
});

it('cannot react to an own comment', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $subject = communityWallMakeSubject($brand->getKey(), 'ada-lovelace');
    $commenter = communityWallMakeMember($brand->getKey());

    $comment = CommunityComment::factory()->create([
        'entity_id' => $subject->getKey(),
        'author_user_id' => $commenter->getKey(),
    ]);

    $this->actingAs($commenter)
        ->post("/u/ada-lovelace/comments/{$comment->getKey()}/react")
        ->assertInvalid(['comment']);

    expect(CommunityReaction::query()->count())->toBe(0);
});

it('unreacts and recounts', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $subject = communityWallMakeSubject($brand->getKey(), 'ada-lovelace');
    $commenter = communityWallMakeMember($brand->getKey());
    $reactor = communityWallMakeMember($brand->getKey());

    $comment = CommunityComment::factory()->create([
        'entity_id' => $subject->getKey(),
        'author_user_id' => $commenter->getKey(),
    ]);

    $this->actingAs($reactor)
        ->post("/u/ada-lovelace/comments/{$comment->getKey()}/react")
        ->assertRedirect();

    expect($comment->refresh()->reaction_count)->toBe(1);

    $this->actingAs($reactor)
        ->delete("/u/ada-lovelace/comments/{$comment->getKey()}/react")
        ->assertRedirect();

    expect(CommunityReaction::query()->count())->toBe(0)
        ->and($comment->refresh()->reaction_count)->toBe(0);
});
