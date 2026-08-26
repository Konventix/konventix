<?php

declare(strict_types=1);

namespace Konventix\Content\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Content\Actions\ModerateComment;
use Konventix\Content\Enums\CommentState;
use Konventix\Content\Enums\Permission;
use Konventix\Content\Models\Author;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Operators\ConsoleSelection;

/**
 * Operator console: the comment moderation queue (ADR-0029).
 *
 * `ModerateComments` is its own capability: a community moderator should be
 * able to hide an abusive comment without also being able to publish the
 * front page.
 *
 * The default view is the queue that needs attention — pending first, then
 * whatever has been reported — because a flat list of every comment ever
 * posted is not a moderation tool. Rejecting and hiding stay separate
 * operations here for the same reason `ModerateComment` keeps them apart: a
 * moderator reviewing their own history needs to know which they did.
 *
 * Comments are brand-owned (ADR-0010) and this is the admin host, so every
 * query escapes `BrandScope` deliberately — including the entry titles, which
 * are fetched as a keyed lookup rather than a relation load, since a relation
 * re-applies the scope on the related model (.ai/rules).
 */
class CommentModerationController
{
    use HasDataTableQuery;

    /** The queue itself: pending plus anything reported, and no `CommentState`. */
    private const ATTENTION = 'attention';

    public function index(Request $request, ConsoleSelection $selection): Response
    {
        Gate::authorize(Permission::ModerateComments->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort(
            $request,
            allowedSorts: ['created_at', 'state', 'report_count', 'reaction_count'],
            default: 'created_at',
        );

        if (! $request->filled('sort')) {
            $sort['direction'] = 'desc';
        }

        $filters = $this->resolveFilters($request, [
            'state' => [...$this->stateValues(), self::ATTENTION],
        ]);

        // `attention` is not a `CommentState`: it is the queue — pending plus
        // anything reported — which is what a moderator opens this page for,
        // so an absent `state` means that rather than "every comment ever
        // posted". A `state` the contract does not know is dropped like any
        // other declared filter's unknown value, which leaves the list
        // unfiltered — the queue default applies only when `state` is absent
        // altogether, never as a correction of a value that was sent.
        if (! $request->has('state')) {
            $filters['state'] = self::ATTENTION;
        }

        // The console's pick is this list's default, cleared per list with
        // `brand=all` (ADR-0029). An event filter is owed here too, but
        // Content has no event read model yet — see TODO.md.
        $filters['brand'] = $this->resolveSelectionFilter($request, 'brand', $selection->brandId());

        $comments = ContentComment::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where('body', 'ilike', $term);
            })
            ->when($filters['brand'] !== null, fn ($query) => $query->forBrand($filters['brand']))
            ->when($filters['state'] === self::ATTENTION, fn ($query) => $query->where(
                fn ($inner) => $inner->where('state', CommentState::Pending)->orWhere('report_count', '>', 0),
            ))
            ->when(
                $filters['state'] !== null && $filters['state'] !== self::ATTENTION,
                fn ($query) => $query->where('state', $filters['state']),
            )
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        /** @var array<string, string> $entryTitles */
        $entryTitles = ContentEntry::withoutBrandScope()
            ->whereIn('id', $comments->getCollection()->pluck('content_entry_id')->unique())
            ->pluck('title', 'id')
            ->all();

        // One lookup for the page, never one per row. `content_authors` is
        // Content's own read model (ADR-0004) and is not brand-owned, so
        // unlike the entry titles it needs no escape from `BrandScope`.
        /** @var array<string, string> $authorNames */
        $authorNames = Author::query()
            ->whereIn('id', $comments->getCollection()->pluck('author_user_id')->unique())
            ->pluck('display_name', 'id')
            ->all();

        return Inertia::render('admin/content/comments/Index', [
            'comments' => $comments->through(fn (ContentComment $comment): array => [
                'id' => $comment->getKey(),
                'entryId' => $comment->content_entry_id,
                'entryTitle' => $entryTitles[$comment->content_entry_id] ?? '—',
                // An account the projection has not seen yet reads the same
                // here as it does on the public page.
                'authorName' => $authorNames[$comment->author_user_id] ?? __('Participant'),
                'authorUrl' => route('admin.users.edit', $comment->author_user_id, absolute: false),
                'body' => $comment->body,
                'state' => $comment->state->value,
                'reportCount' => $comment->report_count,
                'reactionCount' => $comment->reaction_count,
                'postedAt' => $comment->created_at?->toDateTimeString(),
                'moderatedAt' => $comment->moderated_at?->toDateTimeString(),
            ]),
            'brands' => $this->brandOptions(),
            'states' => $this->stateValues(),
            'filters' => $this->currentFilters($request, $sort, $perPage, $filters),
        ]);
    }

    public function moderate(Request $request, string $comment, ModerateComment $moderateComment): RedirectResponse
    {
        Gate::authorize(Permission::ModerateComments->value);

        $validated = $request->validate([
            'state' => ['required', 'string', 'in:approved,rejected,hidden'],
        ]);

        /** @var ContentComment $model */
        $model = ContentComment::withoutBrandScope()->findOrFail($comment);

        $moderateComment->handle(
            $model,
            CommentState::from($validated['state']),
            (string) $request->user()->getKey(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Comment moderated.')]);

        return back();
    }

    /**
     * @return list<string>
     */
    private function stateValues(): array
    {
        return array_map(fn (CommentState $case): string => $case->value, CommentState::cases());
    }
}
