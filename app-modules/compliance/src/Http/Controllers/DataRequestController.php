<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Konventix\Compliance\Actions\OpenSubjectRequest;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Platform\Brands\BrandContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Self-service: a person asking for their own data, or for its deletion,
 * from the privacy settings page (Art. 15 and Art. 17 GDPR).
 *
 * Deletion always opens in `anonymize` mode. Round 6 (D-BATCH) settled
 * anonymize-with-tombstone as the default, and erasure is not something a
 * form should offer: it conflicts with statutory retention on documents
 * nobody may delete for ten years (§147 AO), so choosing it is an operator
 * decision made with the facts of the case in front of them.
 *
 * One open request per type per subject. A second "delete my account" click
 * is the same request, not a new obligation with its own deadline — and a
 * queue of duplicates is how a statutory deadline gets missed on the one
 * that mattered.
 */
final class DataRequestController
{
    public function store(Request $request, OpenSubjectRequest $openSubjectRequest): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:export,deletion'],
        ]);

        $subjectId = (string) $request->user()->getAuthIdentifier();
        $type = DataSubjectRequestType::from($validated['type']);

        $alreadyOpen = DataSubjectRequest::query()
            ->where('subject_id', $subjectId)
            ->where('type', $type)
            ->whereIn('state', [DataSubjectRequestState::Received, DataSubjectRequestState::InProgress])
            ->exists();

        if ($alreadyOpen) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('That request is already being handled.'),
            ]);

            return back();
        }

        $openSubjectRequest(
            SubjectRef::forUser($subjectId),
            $type,
            $type === DataSubjectRequestType::Deletion ? DeletionMode::Anonymize : null,
            app(BrandContext::class)->current()?->getKey(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Request received. You will hear from us within 30 days.'),
        ]);

        return back();
    }

    /**
     * The subject's own download. Authorization is checked here on every
     * request rather than baked into a signed URL: an export is every
     * module's answer about one person, and a link that carries its own
     * authority outlives the mailbox it was sent to.
     */
    public function download(Request $request, string $subjectRequest): StreamedResponse
    {
        /** @var DataSubjectRequest|null $model */
        $model = DataSubjectRequest::query()->find($subjectRequest);

        abort_if($model === null, 404);
        abort_unless($model->subject_id === (string) $request->user()->getAuthIdentifier(), 403);
        abort_unless($model->hasDownloadableArchive(), 404);

        return Storage::disk((string) $model->archive_disk)
            ->download((string) $model->archive_path, 'my-data.json');
    }
}
