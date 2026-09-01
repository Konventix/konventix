<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Community\Gdpr\CommunityGdprHandler;
use Konventix\Community\Models\CommunityComment;
use Konventix\Community\Models\CommunityEventParticipation;
use Konventix\Community\Models\CommunityMember;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Community\Models\CommunityReaction;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Identity\Events\BrandMembershipProfileUpdated;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationSubmitted;

/**
 * Task 10: Community's contribution to the GDPR fan-out — the profile,
 * membership, comment, reaction and event-participation shapes Task 9 and
 * ADR-0052 §3 built up, all read/erased through the same `GdprHandler`
 * contract every other module implements (ADR-0003).
 *
 * Rows are arranged by emitting Identity/Registration events, never by
 * inserting the projected read models directly (ProfileProjectionTest /
 * EventParticipationProjectionTest precedent) — except comments and
 * reactions, which the projections never touch and are arranged directly,
 * the same way `ProfileCommentsTest` and `ContentGdprHandler`'s own tests
 * do.
 */
function communityGdprMakeAccount(string $brandId, string $username, string $visibility = 'internal'): string
{
    $userId = (string) Str::ulid();

    event(new UserRegistered(
        userId: $userId,
        brandId: $brandId,
        email: $username.'@example.test',
        displayName: 'Ada Lovelace',
        username: $username,
        locale: 'en',
        registeredAt: now()->toIso8601String(),
    ));

    event(new UserProfileUpdated(
        userId: $userId,
        email: $username.'@example.test',
        displayName: 'Ada Lovelace',
        username: $username,
        tagline: 'Enchantress of numbers',
        statusEmoji: '💡',
        socialLinks: ['mastodon' => 'https://example.test/@'.$username],
        visibility: $visibility,
        locale: 'en',
        updatedAt: now()->toIso8601String(),
    ));

    event(new BrandMembershipProfileUpdated(
        userId: $userId,
        brandId: $brandId,
        avatarUrl: 'https://example.test/'.$username.'/avatar.png',
        bannerUrl: 'https://example.test/'.$username.'/banner.png',
        firstSeenAt: now()->subDays(3)->toIso8601String(),
        updatedAt: now()->toIso8601String(),
    ));

    return $userId;
}

function communityGdprMakeParticipation(string $brandId, string $userId, bool $confirmed = false): string
{
    $registrationId = (string) Str::ulid();
    $eventId = (string) Str::ulid();

    event(new RegistrationSubmitted(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        registrantUserId: $userId,
        settlement: 'free',
        confirmationGate: 'automatic',
        submittedAt: now()->toIso8601String(),
    ));

    if ($confirmed) {
        event(new RegistrationConfirmed(
            registrationId: $registrationId,
            registrationTypeId: (string) Str::ulid(),
            eventId: $eventId,
            brandId: $brandId,
            confirmedAt: now()->toIso8601String(),
            grants: [],
        ));
    }

    return $eventId;
}

it('exports the profile, membership, authored comments, reactions and event participations', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $subjectId = communityGdprMakeAccount($brand->getKey(), 'ada-lovelace', 'public');
    $otherId = communityGdprMakeAccount($brand->getKey(), 'grace-hopper');

    $eventId = communityGdprMakeParticipation($brand->getKey(), $subjectId, confirmed: true);

    $comment = CommunityComment::factory()->create([
        'entity_id' => $otherId,
        'author_user_id' => $subjectId,
        'body' => 'Welcome to the wall!',
    ]);

    $otherComment = CommunityComment::factory()->create([
        'entity_id' => $subjectId,
        'author_user_id' => $otherId,
    ]);

    CommunityReaction::query()->create([
        'user_id' => $subjectId,
        'module' => CommunityComment::MODULE,
        'entity_type' => CommunityReaction::ENTITY_COMMENT,
        'entity_id' => $otherComment->getKey(),
    ]);

    $handler = app(CommunityGdprHandler::class);
    $export = $handler->exportFor(SubjectRef::forUser($subjectId));

    expect($export['profile']['username'])->toBe('ada-lovelace')
        ->and($export['profile']['visibility'])->toBe('public')
        ->and($export['memberships'])->toHaveCount(1)
        ->and($export['memberships'][0]['brand_id'])->toBe($brand->getKey())
        ->and($export['memberships'][0])->toHaveKey('member_since')
        ->and($export['memberships'][0]['avatar_url'])->toBe('https://example.test/ada-lovelace/avatar.png')
        ->and($export['memberships'][0]['banner_url'])->toBe('https://example.test/ada-lovelace/banner.png')
        ->and($export['comments'])->toHaveCount(1)
        ->and($export['comments'][0]['id'])->toBe($comment->getKey())
        ->and($export['comments'][0]['body'])->toBe('Welcome to the wall!')
        ->and($export['reactions'])->toHaveCount(1)
        ->and($export['reactions'][0]['entity_id'])->toBe($otherComment->getKey())
        ->and($export['event_participations'])->toHaveCount(1)
        ->and($export['event_participations'][0]['brand_id'])->toBe($brand->getKey())
        ->and($export['event_participations'][0]['event_id'])->toBe($eventId)
        ->and($export['event_participations'][0]['confirmed'])->toBeTrue();
});

it('deletes reactions first and recounts the comments they sat on, then authored comments, participations, membership and profile', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $subjectId = communityGdprMakeAccount($brand->getKey(), 'ada-lovelace');
    $otherId = communityGdprMakeAccount($brand->getKey(), 'grace-hopper');

    communityGdprMakeParticipation($brand->getKey(), $subjectId);

    // A comment someone else wrote, the subject reacted to it.
    $othersComment = CommunityComment::factory()->create([
        'entity_id' => $otherId,
        'author_user_id' => $otherId,
    ]);
    CommunityReaction::query()->create([
        'user_id' => $subjectId,
        'module' => CommunityComment::MODULE,
        'entity_type' => CommunityReaction::ENTITY_COMMENT,
        'entity_id' => $othersComment->getKey(),
    ]);
    $othersComment->forceFill(['reaction_count' => CommunityReaction::countFor($othersComment)])->save();
    expect($othersComment->refresh()->reaction_count)->toBe(1);

    // A comment the subject wrote, someone else reacted to it.
    $subjectsComment = CommunityComment::factory()->create([
        'entity_id' => $otherId,
        'author_user_id' => $subjectId,
    ]);
    CommunityReaction::query()->create([
        'user_id' => $otherId,
        'module' => CommunityComment::MODULE,
        'entity_type' => CommunityReaction::ENTITY_COMMENT,
        'entity_id' => $subjectsComment->getKey(),
    ]);

    $handler = app(CommunityGdprHandler::class);
    $handler->delete(SubjectRef::forUser($subjectId), DeletionMode::Erase);

    // The subject's own reaction is gone, and the comment it sat on is
    // recounted rather than left stale.
    expect(CommunityReaction::withoutBrandScope()->where('user_id', $subjectId)->exists())->toBeFalse()
        ->and($othersComment->refresh()->reaction_count)->toBe(0)
        ->and(CommunityComment::withoutBrandScope()->whereKey($othersComment->getKey())->exists())->toBeTrue();

    // The subject's own comment is gone, and so is the reaction someone
    // else left on it — no dangling reaction pointing at a deleted comment.
    expect(CommunityComment::withoutBrandScope()->whereKey($subjectsComment->getKey())->exists())->toBeFalse()
        ->and(CommunityReaction::withoutBrandScope()->where('entity_id', $subjectsComment->getKey())->exists())->toBeFalse();

    expect(CommunityEventParticipation::withoutBrandScope()->where('user_id', $subjectId)->exists())->toBeFalse()
        ->and(CommunityMember::withoutBrandScope()->where('user_id', $subjectId)->exists())->toBeFalse()
        ->and(CommunityProfile::query()->find($subjectId))->toBeNull();

    // Idempotent: calling again touches nothing further and raises nothing.
    $handler->delete(SubjectRef::forUser($subjectId), DeletionMode::Erase);

    expect(CommunityProfile::query()->find($subjectId))->toBeNull()
        ->and(CommunityMember::withoutBrandScope()->where('user_id', $subjectId)->exists())->toBeFalse();
});

it('no-ops both ways for an email-only subject', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('community', $brand);

    $otherId = communityGdprMakeAccount($brand->getKey(), 'grace-hopper');
    $comment = CommunityComment::factory()->create([
        'entity_id' => $otherId,
        'author_user_id' => $otherId,
    ]);

    $handler = app(CommunityGdprHandler::class);
    $subject = SubjectRef::forEmail('nobody@example.test');

    expect($handler->exportFor($subject))->toBe([]);

    $handler->delete($subject, DeletionMode::Erase);

    expect(CommunityProfile::query()->find($otherId))->not->toBeNull()
        ->and(CommunityComment::withoutBrandScope()->whereKey($comment->getKey())->exists())->toBeTrue();
});
