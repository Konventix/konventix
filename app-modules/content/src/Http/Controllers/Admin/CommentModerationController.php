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
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;

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

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ModerateComments->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort(
            $request,
            allowedSorts: ['created_at', 'state', 'report_count'],
            default: 'created_at',
        );

        if (! $request->filled('sort')) {
            $sort['direction'] = 'desc';
        }

        $state = $this->resolveState($request);

        $comments = ContentComment::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where('body', 'ilike', $term);
            })
            ->when($state === 'attention', fn ($query) => $query->where(
                fn ($inner) => $inner->where('state', CommentState::Pending)->orWhere('report_count', '>', 0),
            ))
            ->when($state !== null && $state !== 'attention', fn ($query) => $query->where('state', $state))
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        /** @var array<string, string> $entryTitles */
        $entryTitles = ContentEntry::withoutBrandScope()
            ->whereIn('id', $comments->getCollection()->pluck('content_entry_id')->unique())
            ->pluck('title', 'id')
            ->all();

        return Inertia::render('admin/content/comments/Index', [
            'comments' => $comments->through(fn (ContentComment $comment): array => [
                'id' => $comment->getKey(),
                'entryId' => $comment->content_entry_id,
                'entryTitle' => $entryTitles[$comment->content_entry_id] ?? '—',
                'authorUserId' => $comment->author_user_id,
                'body' => $comment->body,
                'state' => $comment->state->value,
                'reportCount' => $comment->report_count,
                'postedAt' => $comment->created_at?->toDateTimeString(),
                'moderatedAt' => $comment->moderated_at?->toDateTimeString(),
            ]),
            'states' => array_map(fn (CommentState $case): string => $case->value, CommentState::cases()),
            'filters' => [
                ...$this->currentFilters($request, $sort, $perPage),
                'state' => $state,
            ],
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
     * `attention` is the default and is not a `CommentState`: it is the queue
     * — pending plus anything reported — which is what a moderator opens this
     * page for.
     */
    private function resolveState(Request $request): ?string
    {
        $value = $request->string('state')->toString();

        if ($value === 'all') {
            return null;
        }

        $allowed = ['attention', ...array_map(fn (CommentState $case): string => $case->value, CommentState::cases())];

        return in_array($value, $allowed, true) ? $value : 'attention';
    }
}
