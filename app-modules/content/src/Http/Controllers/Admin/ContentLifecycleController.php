<?php

declare(strict_types=1);

namespace Konventix\Content\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Konventix\Content\Actions\ArchiveEntry;
use Konventix\Content\Actions\PublishEntry;
use Konventix\Content\Actions\UnpublishEntry;
use Konventix\Content\Enums\Permission;
use Konventix\Content\Models\ContentEntry;

/**
 * One endpoint per lifecycle Action — the Actions own every guard and every
 * emitted event, so this controller only translates HTTP into a call.
 *
 * Everything here needs `PublishEntries`, not `ManageEntries`: Content splits
 * authoring from publishing on purpose, so an author can write an entry that
 * an editor has to release. Archiving counts as publishing because it takes a
 * live entry off the site.
 *
 * Publishing with a future `publishedAt` produces `Scheduled`, not
 * `Published` — that is `PublishEntry`'s decision, and the console shows
 * whichever state came back rather than assuming.
 */
class ContentLifecycleController
{
    public function publish(Request $request, string $entry, PublishEntry $publishEntry): RedirectResponse
    {
        Gate::authorize(Permission::PublishEntries->value);

        $validated = $request->validate(['publishedAt' => ['nullable', 'date']]);
        $at = isset($validated['publishedAt']) ? Carbon::parse($validated['publishedAt']) : null;

        $model = $publishEntry->handle($this->find($entry), $at);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $model->state->value === 'scheduled'
                ? __('Entry scheduled.')
                : __('Entry published.'),
        ]);

        return back();
    }

    public function unpublish(string $entry, UnpublishEntry $unpublishEntry): RedirectResponse
    {
        Gate::authorize(Permission::PublishEntries->value);

        $unpublishEntry->handle($this->find($entry));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Entry unpublished.')]);

        return back();
    }

    public function archive(string $entry, ArchiveEntry $archiveEntry): RedirectResponse
    {
        Gate::authorize(Permission::PublishEntries->value);

        $archiveEntry->handle($this->find($entry));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Entry archived.')]);

        return back();
    }

    private function find(string $entry): ContentEntry
    {
        /** @var ContentEntry */
        return ContentEntry::withoutBrandScope()->findOrFail($entry);
    }
}
