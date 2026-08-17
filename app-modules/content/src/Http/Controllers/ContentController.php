<?php

declare(strict_types=1);

namespace Konventix\Content\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Content\Data\PublicCommentData;
use Konventix\Content\Data\PublicEntryData;
use Konventix\Content\Enums\Audience;
use Konventix\Content\Enums\CommentState;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Enums\Urgency;
use Konventix\Content\Models\Author;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;

/**
 * The brand's public editorial surface, resolved on the brand's own domain.
 *
 * Brand scoping is `BelongsToBrand`'s global scope — this is a brand host, so
 * unlike the operator console nothing here escapes it (ADR-0010).
 *
 * Two rules run on every read:
 *
 * - **Live only.** `scopeLive` asks the state *and* the date, so a scheduled
 *   entry whose moment passed is invisible until `PublishScheduledEntries`
 *   promotes it, and an entry unpublished before its date never appears.
 * - **Audience.** A `logged-in` entry is absent from the listing for a guest
 *   and 404s on direct access. Not a redirect to the login page: that would
 *   confirm the slug exists, and D-CNT-07 makes audience a visibility rule,
 *   not a paywall.
 */
final class ContentController
{
    public function index(Request $request): Response
    {
        $viewerId = $this->viewerId($request);

        $entries = ContentEntry::query()
            ->live()
            ->whereIn('type', [ContentType::News, ContentType::Announcement])
            ->when($viewerId === null, fn ($query) => $query->where('audience', Audience::Everyone))
            ->orderByDesc('published_at')
            ->paginate(10)
            ->withQueryString();

        $authors = $this->authorNames($entries->getCollection()->pluck('author_user_id')->all());

        return Inertia::render('content/Index', [
            'entries' => $entries->through(fn (ContentEntry $entry): array => PublicEntryData::ofModel(
                $entry,
                $authors[$entry->author_user_id] ?? null,
                withBody: false,
            )->toArray()),
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $viewerId = $this->viewerId($request);

        /** @var ContentEntry|null $entry */
        $entry = ContentEntry::query()->live()->where('slug', $slug)->first();

        abort_if($entry === null, 404);
        abort_if($entry->audience === Audience::LoggedIn && $viewerId === null, 404);

        $comments = $entry->comments_enabled
            ? ContentComment::query()
                ->where('content_entry_id', $entry->getKey())
                ->where('state', CommentState::Approved)
                ->orderBy('created_at')
                ->get()
            : collect();

        $authors = $this->authorNames([
            $entry->author_user_id,
            ...$comments->pluck('author_user_id')->all(),
        ]);

        return Inertia::render('content/Show', [
            'entry' => PublicEntryData::ofModel($entry, $authors[$entry->author_user_id] ?? null)->toArray(),
            'comments' => $comments
                ->map(fn (ContentComment $comment): array => PublicCommentData::ofModel(
                    $comment,
                    $authors[$comment->author_user_id] ?? null,
                    $viewerId,
                )->toArray())
                ->all(),
            'canComment' => $entry->comments_enabled && $viewerId !== null,
        ]);
    }

    /**
     * The site-wide banner's feed, fetched by the banner component the way
     * the notification bell fetches its own (ADR-0041 point 6's pattern).
     *
     * **Urgent announcements only.** A banner that carries every announcement
     * is wallpaper nobody reads; the normal ones are in the listing. That is
     * a presentation rule living where presentation belongs — Content still
     * declares urgency as intent and nothing more (D-CNT-08).
     */
    public function announcements(Request $request): JsonResponse
    {
        $viewerId = $this->viewerId($request);

        $announcements = ContentEntry::query()
            ->live()
            ->where('type', ContentType::Announcement)
            ->where('urgency', Urgency::Urgent)
            ->when($viewerId === null, fn ($query) => $query->where('audience', Audience::Everyone))
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        return response()->json([
            'announcements' => $announcements
                ->map(fn (ContentEntry $entry): array => PublicEntryData::ofModel($entry, withBody: false)->toArray())
                ->all(),
        ]);
    }

    private function viewerId(Request $request): ?string
    {
        $user = $request->user();

        return $user === null ? null : (string) $user->getKey();
    }

    /**
     * @param  array<int, string|null>  $userIds
     * @return array<string, string>
     */
    private function authorNames(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter($userIds)));

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> */
        return Author::query()->whereKey($ids)->pluck('display_name', 'id')->all();
    }
}
