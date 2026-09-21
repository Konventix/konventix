<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Content\Actions\ModerateComment;
use Konventix\Content\Actions\PostComment;
use Konventix\Content\Actions\PublishEntry;
use Konventix\Content\Actions\PublishScheduledEntries;
use Konventix\Content\Actions\UnpublishEntry;
use Konventix\Content\Blocks\Types\LatestNewsBlock;
use Konventix\Content\Enums\CommentState;
use Konventix\Content\Enums\PublicationState;
use Konventix\Content\Enums\Urgency;
use Konventix\Content\Events\AnnouncementPublished;
use Konventix\Content\Events\CommentModerated;
use Konventix\Content\Events\CommentPosted;
use Konventix\Content\Events\ContentEntryPublished;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;
use Konventix\Platform\Blocks\BlockRegistry;

use function Pest\Laravel\travelTo;

/**
 * Publishing, announcements (D-CNT-08) and comments (D-BATCH-7).
 * Helper names prefixed — Pest helpers are process-global (.ai/rules/tests.md).
 */
it('publishes an entry and emits the contract event', function (): void {
    $this->withBrand();

    $entry = ContentEntry::factory()->create();

    Event::fake([ContentEntryPublished::class, AnnouncementPublished::class]);

    $published = app(PublishEntry::class)->handle($entry);

    expect($published->state)->toBe(PublicationState::Published)
        ->and($published->isLive())->toBeTrue();

    Event::assertDispatched(ContentEntryPublished::class);
    // A news entry is not an announcement; nobody should be notified.
    Event::assertNotDispatched(AnnouncementPublished::class);
});

it('emits both events for an announcement, because it is still an entry', function (): void {
    // D-CNT-08: Content declares intent, Messaging maps it to channels.
    $this->withBrand();

    $entry = ContentEntry::factory()->announcement(Urgency::Urgent)->create();

    Event::fake([ContentEntryPublished::class, AnnouncementPublished::class]);

    app(PublishEntry::class)->handle($entry);

    Event::assertDispatched(ContentEntryPublished::class);
    Event::assertDispatched(
        AnnouncementPublished::class,
        fn (AnnouncementPublished $e): bool => $e->urgency === 'urgent' && $e->audience === 'everyone',
    );
});

it('carries intent only, never a channel or a delivery time', function (): void {
    // D-CNT-08 gives Messaging sole authority over *how* an announcement is
    // delivered; Content deciding that would make the same announcement mean
    // different things in different modules. Checked by reflecting over the
    // payload rather than scanning the file, so the docblock explaining the
    // rule cannot trip the test that enforces it.
    $properties = array_map(
        fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(AnnouncementPublished::class))->getProperties(ReflectionProperty::IS_PUBLIC),
    );

    expect($properties)->toEqualCanonicalizing([
        'entryId', 'brandId', 'eventId', 'slug', 'title', 'urgency', 'audience', 'publishedAt',
    ]);
});

it('stores the moment it was handed in UTC, whatever zone it carried (#246)', function (): void {
    // The Action owns the transition, so it owns this invariant: Eloquent
    // formats a Carbon in whatever zone the instance carries, and
    // `content_entries.published_at` is a zone-less column — so a moment
    // parsed as `+02:00` would be written down as its own digits and read back
    // two hours wrong. The controller normalised it before this lived here;
    // any other caller would have reintroduced the bug (#246 review 11).
    $this->withBrand();

    $entry = ContentEntry::factory()->create();

    $moment = CarbonImmutable::parse('2099-06-08T22:00:00+02:00');

    $published = app(PublishEntry::class)->handle($entry, $moment);

    expect($published->published_at->utc()->toDateTimeString())->toBe('2099-06-08 20:00:00');
});

it('schedules a future publish rather than marking it live', function (): void {
    // "Is this live" should be answerable without arithmetic.
    $this->withBrand();

    $entry = ContentEntry::factory()->create();

    Event::fake([ContentEntryPublished::class]);

    $scheduled = app(PublishEntry::class)->handle($entry, now()->addDay());

    expect($scheduled->state)->toBe(PublicationState::Scheduled)
        ->and($scheduled->isLive())->toBeFalse();

    // Nothing is announced until it is actually live.
    Event::assertNotDispatched(ContentEntryPublished::class);
});

it('promotes a scheduled entry once its moment arrives', function (): void {
    $this->withBrand();

    $entry = ContentEntry::factory()->create();
    app(PublishEntry::class)->handle($entry, now()->addDay());

    travelTo(now()->addDays(2));

    Event::fake([ContentEntryPublished::class]);

    expect(app(PublishScheduledEntries::class)->handle())->toBe(1)
        ->and($entry->fresh()->state)->toBe(PublicationState::Published);

    // Announced when it goes live, not a day early.
    Event::assertDispatched(ContentEntryPublished::class);
});

it('does not resurrect an entry unpublished before its scheduled date', function (): void {
    // This is the whole reason Scheduled is a distinct state rather than a
    // published row with a future timestamp.
    $this->withBrand();

    $entry = ContentEntry::factory()->create();
    app(PublishEntry::class)->handle($entry, now()->addDay());
    app(UnpublishEntry::class)->handle($entry->fresh());

    travelTo(now()->addDays(2));

    expect(app(PublishScheduledEntries::class)->handle())->toBe(0)
        ->and($entry->fresh()->state)->toBe(PublicationState::Unpublished);
});

it('is idempotent when republishing', function (): void {
    $this->withBrand();

    $entry = ContentEntry::factory()->published()->create();

    Event::fake([ContentEntryPublished::class]);

    app(PublishEntry::class)->handle($entry);

    // Re-notifying everyone about yesterday's news is the failure mode here.
    Event::assertNotDispatched(ContentEntryPublished::class);
});

it('excludes drafts and future entries from the live scope', function (): void {
    $this->withBrand();

    ContentEntry::factory()->create();
    ContentEntry::factory()->published()->create();
    $scheduled = ContentEntry::factory()->create();
    app(PublishEntry::class)->handle($scheduled, now()->addWeek());

    expect(ContentEntry::query()->live()->count())->toBe(1);
});

it('accepts a comment from an account holder on a live entry', function (): void {
    $this->withBrand();

    $entry = ContentEntry::factory()->published()->withComments()->create();
    $userId = (string) Str::ulid();

    Event::fake([CommentPosted::class]);

    $comment = app(PostComment::class)->handle($entry, $userId, 'Looking forward to it.');

    expect($comment->state)->toBe(CommentState::Approved)
        ->and($comment->author_user_id)->toBe($userId);

    Event::assertDispatched(CommentPosted::class);
});

it('holds a comment for review when the brand pre-moderates', function (): void {
    $this->withBrand();

    $entry = ContentEntry::factory()->published()->withComments()->create();

    $comment = app(PostComment::class)->handle($entry, (string) Str::ulid(), 'Hello', preModerate: true);

    expect($comment->state)->toBe(CommentState::Pending)
        ->and($comment->isVisible())->toBeFalse();
});

it('refuses a comment when comments are disabled', function (): void {
    $this->withBrand();

    $entry = ContentEntry::factory()->published()->create();

    expect(fn () => app(PostComment::class)->handle($entry, (string) Str::ulid(), 'Hi'))
        ->toThrow(RuntimeException::class);
});

it('refuses a comment on an entry that is not live', function (): void {
    $this->withBrand();

    $entry = ContentEntry::factory()->withComments()->create();

    expect(fn () => app(PostComment::class)->handle($entry, (string) Str::ulid(), 'Hi'))
        ->toThrow(RuntimeException::class);
});

it('distinguishes hiding a public comment from rejecting a submission', function (): void {
    // A moderator reviewing their own history needs to know which they did.
    $this->withBrand();

    $approved = ContentComment::factory()->create();
    $pending = ContentComment::factory()->pending()->create();
    $moderator = (string) Str::ulid();

    Event::fake([CommentModerated::class]);

    app(ModerateComment::class)->handle($approved, CommentState::Hidden, $moderator);
    app(ModerateComment::class)->handle($pending, CommentState::Rejected, $moderator);

    expect($approved->fresh()->state)->toBe(CommentState::Hidden)
        ->and($pending->fresh()->state)->toBe(CommentState::Rejected)
        ->and($approved->fresh()->moderated_by_user_id)->toBe($moderator);

    Event::assertDispatchedTimes(CommentModerated::class, 2);
});

it('counts reports rather than recording who flagged', function (): void {
    // A moderator needs a queue ordered by attention, not a forensic record.
    $this->withBrand();

    $comment = ContentComment::factory()->create();

    app(ModerateComment::class)->report($comment);
    app(ModerateComment::class)->report($comment->fresh());

    expect($comment->fresh()->report_count)->toBe(2);
});

it('contributes its block to Platform registry rather than a parallel one', function (): void {
    $this->withBrand();

    expect(app(BlockRegistry::class)->resolveActive('latest-news'))
        ->toBeInstanceOf(LatestNewsBlock::class);
});

it('resolves the newest live news at render time, not at edit time', function (): void {
    // Storing entries in the block would freeze whichever articles were newest
    // the day an editor dropped it in.
    $this->withBrand();

    ContentEntry::factory()->published()->create(['title' => 'Older']);
    travelTo(now()->addMinute());
    ContentEntry::factory()->published()->create(['title' => 'Newer']);
    ContentEntry::factory()->create(['title' => 'Draft']);

    $props = (new LatestNewsBlock)->resolve(['limit' => 5]);

    expect($props['entries'])->toHaveCount(2)
        ->and($props['entries'][0]['title'])->toBe('Newer')
        ->and(collect($props['entries'])->pluck('title'))->not->toContain('Draft');
});

it('honours the block limit', function (): void {
    $this->withBrand();

    ContentEntry::factory()->published()->count(4)->create();

    expect((new LatestNewsBlock)->resolve(['limit' => 2])['entries'])->toHaveCount(2);
});
