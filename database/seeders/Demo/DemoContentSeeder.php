<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Konventix\Content\Actions\CreateEntry;
use Konventix\Content\Actions\ModerateComment;
use Konventix\Content\Actions\PostComment;
use Konventix\Content\Actions\PublishEntry;
use Konventix\Content\Data\ContentEntryData;
use Konventix\Content\Enums\Audience;
use Konventix\Content\Enums\CommentState;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Enums\Urgency;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;
use Konventix\Event\Models\Event;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;

/**
 * The newsroom: a published article with a live comment thread, a
 * logged-in-only post, a draft nobody has released, and an urgent
 * announcement.
 *
 * The announcement is not decoration. Publishing one emits
 * `AnnouncementPublished`, which Messaging turns into a per-recipient
 * fan-out across the channels each account has left enabled (ADR-0041) —
 * so this is also what puts real rows in the delivery log and the
 * notification bell.
 *
 * Comments land in every state the moderation queue distinguishes:
 * approved, pending pre-moderation, rejected, and one that readers have
 * reported.
 *
 * Idempotent per slug.
 */
final class DemoContentSeeder
{
    public function __construct(
        private readonly CreateEntry $createEntry,
        private readonly PublishEntry $publishEntry,
        private readonly PostComment $postComment,
        private readonly ModerateComment $moderateComment,
    ) {}

    /**
     * @param  Collection<int, User>  $members
     */
    public function handle(Brand $brand, ?Event $event, Collection $members): void
    {
        $author = $members->first();

        if ($author === null) {
            return;
        }

        $lineup = $this->seedEntry(
            $brand,
            $author,
            type: ContentType::News,
            title: 'The '.$brand->name.' line-up is live',
            teaser: 'Three days, two halls, one very long weekend.',
            eventId: $event?->getKey(),
            commentsEnabled: true,
            publish: true,
        );

        $this->seedEntry(
            $brand,
            $author,
            type: ContentType::News,
            title: 'Crew briefing and shift handover',
            teaser: 'Everything the team needs before doors open.',
            eventId: $event?->getKey(),
            audience: Audience::LoggedIn,
            publish: true,
        );

        $this->seedEntry(
            $brand,
            $author,
            type: ContentType::News,
            title: 'After-show report (draft)',
            teaser: 'Numbers, photos and thank-yous. Not finished.',
            eventId: $event?->getKey(),
            publish: false,
        );

        $this->seedEntry(
            $brand,
            $author,
            type: ContentType::Announcement,
            title: 'Hall B doors open one hour later',
            teaser: 'A delivery is blocking the loading bay until 11:00.',
            eventId: $event?->getKey(),
            urgency: Urgency::Urgent,
            publish: true,
        );

        $this->seedComments($lineup, $members, $author);
    }

    private function seedEntry(
        Brand $brand,
        User $author,
        ContentType $type,
        string $title,
        string $teaser,
        ?string $eventId,
        bool $publish,
        Audience $audience = Audience::Everyone,
        ?Urgency $urgency = null,
        bool $commentsEnabled = false,
    ): ContentEntry {
        $slug = Str::slug($brand->slug.'-'.$title);

        $existing = ContentEntry::query()->firstWhere('slug', $slug);

        if ($existing !== null) {
            return $existing;
        }

        $entry = $this->createEntry->handle(
            $brand->getKey(),
            new ContentEntryData(
                type: $type,
                title: $title,
                slug: $slug,
                audience: $audience,
                commentsEnabled: $commentsEnabled,
                teaser: $teaser,
                body: fake()->text(800),
                urgency: $urgency,
                eventId: $eventId,
            ),
            $author->getKey(),
        );

        // Publication is a separate act, never a side effect of creation:
        // it emits, and for an announcement it notifies.
        return $publish ? $this->publishEntry->handle($entry) : $entry;
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function seedComments(ContentEntry $entry, Collection $members, User $moderator): void
    {
        if (ContentComment::query()->where('content_entry_id', $entry->getKey())->exists()) {
            return;
        }

        $commenters = $members->take(4)->values();

        if ($commenters->isEmpty()) {
            return;
        }

        $bodies = [
            'Finally. Booking the train now.',
            'Is the hall accessible from the north entrance?',
            'BUY CHEAP TICKETS AT example.invalid',
            'Last year was great, looking forward to it.',
        ];

        $commenters->each(function (User $user, int $index) use ($entry, $bodies, $moderator): void {
            $comment = $this->postComment->handle(
                $entry,
                $user->getKey(),
                $bodies[$index] ?? fake()->sentence(),
                // The third one arrives while pre-moderation is on, so the
                // queue has something waiting on a decision.
                preModerate: $index === 2,
            );

            if ($index === 2) {
                // …and is then rejected, which is what the queue is for.
                $this->moderateComment->handle($comment, CommentState::Rejected, $moderator->getKey());

                return;
            }

            // One approved comment carries reader reports: the counter is a
            // queue-ordering hint, not a threshold (see Content's TODO).
            if ($index === 1) {
                $comment->forceFill(['report_count' => 3])->save();
            }
        });
    }
}
