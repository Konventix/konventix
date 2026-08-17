<?php

declare(strict_types=1);

namespace Konventix\Content\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Konventix\Content\Actions\ModerateComment;
use Konventix\Content\Actions\PostComment;
use Konventix\Content\Data\PostCommentData;
use Konventix\Content\Enums\Audience;
use Konventix\Content\Enums\CommentState;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;
use RuntimeException;

/**
 * Reader-side comment endpoints. Both require an account: comments require
 * one by decision (D-BATCH-7), so the routes carry `auth` and there is no
 * anonymous path to guard against.
 *
 * `PostComment` owns the rules about whether a comment may exist at all
 * (entry live, comments enabled). This controller translates the Action's
 * refusal into a form error rather than letting a 500 out, because from the
 * reader's side "comments just closed" is an ordinary race, not a fault.
 */
final class CommentController
{
    public function store(Request $request, string $slug, PostComment $postComment): RedirectResponse
    {
        $entry = $this->findReadableEntry($request, $slug);
        $data = PostCommentData::validateAndCreate($request->all());

        try {
            $postComment->handle($entry, (string) $request->user()->getKey(), $data->body);
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'body' => __('Comments are closed on this entry.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Comment posted.')]);

        return back();
    }

    /**
     * Reporting only increments a counter — what a moderator needs is a queue
     * ordered by attention, not a record of who clicked the flag. It is
     * therefore also idempotent-ish by design: a second report from the same
     * reader raises the same counter, and that is accepted (`TODO.md`).
     */
    public function report(Request $request, string $slug, string $comment, ModerateComment $moderateComment): RedirectResponse
    {
        $entry = $this->findReadableEntry($request, $slug);

        /** @var ContentComment|null $model */
        $model = ContentComment::query()
            ->where('content_entry_id', $entry->getKey())
            ->where('state', CommentState::Approved)
            ->whereKey($comment)
            ->first();

        abort_if($model === null, 404);

        $moderateComment->report($model);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reported. A moderator will look at it.')]);

        return back();
    }

    /**
     * The same visibility rules the reader's page ran: a comment cannot be
     * posted onto something the poster could not have read.
     */
    private function findReadableEntry(Request $request, string $slug): ContentEntry
    {
        /** @var ContentEntry|null $entry */
        $entry = ContentEntry::query()->live()->where('slug', $slug)->first();

        abort_if($entry === null, 404);
        abort_if($entry->audience === Audience::LoggedIn && $request->user() === null, 404);

        return $entry;
    }
}
