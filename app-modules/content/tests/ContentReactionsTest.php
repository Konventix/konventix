<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Content\Actions\ReactToComment;
use Konventix\Content\Actions\UnreactToComment;
use Konventix\Content\Events\CommentReacted;
use Konventix\Content\Events\CommentReactionRemoved;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentReaction;

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
