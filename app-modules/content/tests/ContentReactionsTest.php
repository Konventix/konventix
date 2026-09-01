<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Content\Actions\ReactToComment;
use Konventix\Content\Actions\UnreactToComment;
use Konventix\Content\Enums\CommentState;
use Konventix\Content\Events\CommentReacted;
use Konventix\Content\Events\CommentReactionRemoved;
use Konventix\Content\Gdpr\ContentGdprHandler;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;
use Konventix\Content\Models\ContentReaction;
use Konventix\Identity\Models\User;

use function Pest\Laravel\actingAs;

/**
 * A like on an editorial comment is Content's (D-CNT-02 as the roadmap
 * restated it). One row per (user, subject), a denormalized count that is
 * recounted rather than incremented so a replay converges — the opposite of
 * `report_count`, which is deliberately not deduplicated.
 */
it('records one reaction per person and counts it on the comment', function (): void {
    $this->withBrand();
    $comment = ContentComment::factory()->create();
    $user = (string) Str::ulid();

    Event::fake([CommentReacted::class]);

    app(ReactToComment::class)->handle($comment, $user);
    app(ReactToComment::class)->handle($comment, $user);

    expect(ContentReaction::query()->count())->toBe(1)
        ->and($comment->refresh()->reaction_count)->toBe(1)
        ->and(ContentReaction::query()->sole())->toMatchArray([
            'user_id' => $user,
            'module' => 'content',
            'entity_type' => 'comment',
            'entity_id' => $comment->getKey(),
        ]);

    Event::assertDispatchedTimes(CommentReacted::class, 1);
    Event::assertDispatched(CommentReacted::class, fn (CommentReacted $event): bool => $event->commentId === $comment->getKey()
        && $event->entryId === $comment->content_entry_id
        && $event->brandId === $comment->brand_id
        && $event->userId === $user);
});

it('removes the reaction and recounts, once', function (): void {
    $this->withBrand();
    $comment = ContentComment::factory()->create();
    $ada = (string) Str::ulid();
    $grace = (string) Str::ulid();

    app(ReactToComment::class)->handle($comment, $ada);
    app(ReactToComment::class)->handle($comment, $grace);
    expect($comment->refresh()->reaction_count)->toBe(2);

    Event::fake([CommentReactionRemoved::class]);

    app(UnreactToComment::class)->handle($comment, $ada);
    app(UnreactToComment::class)->handle($comment, $ada);

    expect($comment->refresh()->reaction_count)->toBe(1)
        ->and(ContentReaction::query()->where('user_id', $ada)->exists())->toBeFalse();

    Event::assertDispatchedTimes(CommentReactionRemoved::class, 1);
});

it('likes and unlikes through the reader endpoints', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $entry = ContentEntry::factory()->published()->withComments()->create(['slug' => 'liked']);
    $comment = ContentComment::factory()->create(['content_entry_id' => $entry->id]);

    actingAs($user)->post("/news/liked/comments/{$comment->id}/reaction")->assertRedirect();
    expect($comment->refresh()->reaction_count)->toBe(1);

    actingAs($user)
        ->get('/news/liked')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('comments.0.reactionCount', 1)
            ->where('comments.0.hasReacted', true));

    actingAs($user)->delete("/news/liked/comments/{$comment->id}/reaction")->assertRedirect();
    expect($comment->refresh()->reaction_count)->toBe(0);
});

it('refuses a guest, the author, and a comment that is not public', function (): void {
    $this->withBrand();
    $author = User::factory()->create();
    $entry = ContentEntry::factory()->published()->withComments()->create(['slug' => 'guarded']);
    $own = ContentComment::factory()->create(['content_entry_id' => $entry->id, 'author_user_id' => $author->id]);
    $hidden = ContentComment::factory()->create(['content_entry_id' => $entry->id, 'state' => CommentState::Hidden]);

    $this->post("/news/guarded/comments/{$own->id}/reaction")->assertRedirect('/login');

    actingAs($author)->post("/news/guarded/comments/{$own->id}/reaction")->assertSessionHasErrors('comment');

    actingAs($author)->post("/news/guarded/comments/{$hidden->id}/reaction")->assertNotFound();

    expect(ContentReaction::query()->count())->toBe(0);
});

it('exports a subject\'s reactions and removes them on deletion, recounting', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $comment = ContentComment::factory()->create();
    app(ReactToComment::class)->handle($comment, $user->id);
    app(ReactToComment::class)->handle($comment, (string) Str::ulid());

    $handler = new ContentGdprHandler;
    $subject = SubjectRef::forUser($user->id);

    expect($handler->exportFor($subject)['reactions'])->toHaveCount(1);

    $handler->delete($subject, DeletionMode::Erase);

    expect(ContentReaction::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($comment->refresh()->reaction_count)->toBe(1);
});
