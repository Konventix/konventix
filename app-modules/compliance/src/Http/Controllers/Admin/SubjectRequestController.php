<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Compliance\Actions\ApproveConsentWithdrawal;
use Konventix\Compliance\Actions\RedispatchSubjectRequest;
use Konventix\Compliance\Actions\RefuseSubjectRequest;
use Konventix\Compliance\Audit\AuditRecorder;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\ModuleResponseState;
use Konventix\Compliance\Enums\Permission;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\DataSubjectRequestResponse;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Operator console: the data subject request queue (ADR-0029).
 *
 * Three capabilities, and the split is the point of the module. Seeing that
 * a request exists (`ViewSubjectRequests`) is not the same as acting on it
 * (`HandleSubjectRequests`), and neither is the same as reading what came
 * back (`DownloadSubjectExport`) — that archive is every module's answer
 * about one person, so an operator who runs the queue is not automatically
 * allowed to read its contents (SPEC "Capabilities").
 *
 * Requests are brand-owned and the admin host hardens `BrandScope` to zero
 * rows (ADR-0029), so every query escapes it deliberately — the per-module
 * response rows included, which are read through their own model rather than
 * an eager-loaded relation for exactly that reason (.ai/rules).
 */
class SubjectRequestController
{
    use HasDataTableQuery;

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ViewSubjectRequests->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort(
            $request,
            allowedSorts: ['deadline_at', 'created_at', 'state', 'type'],
            default: 'deadline_at',
        );

        $state = $this->filterValue($request, 'state', array_column(DataSubjectRequestState::cases(), 'value'));
        $type = $this->filterValue($request, 'type', array_column(DataSubjectRequestType::cases(), 'value'));

        $requests = DataSubjectRequest::query()
            ->when($request->filled('search'), fn ($query) => $query->where('subject_id', 'ilike', '%'.$request->string('search')->trim().'%'))
            ->when($state !== null, fn ($query) => $query->where('state', $state))
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        /** @var array<string, string> $brandNames */
        $brandNames = Brand::query()->pluck('name', 'id')->all();

        $pending = DataSubjectRequestResponse::query()
            ->whereIn('data_subject_request_id', $requests->getCollection()->pluck('id'))
            ->where('state', '!=', ModuleResponseState::Completed)
            ->selectRaw('data_subject_request_id, count(*) as aggregate')
            ->groupBy('data_subject_request_id')
            ->pluck('aggregate', 'data_subject_request_id');

        return Inertia::render('admin/subject-requests/Index', [
            'requests' => $requests->through(fn (DataSubjectRequest $subjectRequest): array => [
                'id' => $subjectRequest->getKey(),
                'subjectId' => $subjectRequest->subject_id,
                'brandName' => $brandNames[$subjectRequest->filed_on_brand_id] ?? '—',
                'type' => $subjectRequest->type->value,
                'state' => $subjectRequest->state->value,
                'deadlineAt' => $subjectRequest->deadline_at->toDateString(),
                // Days left rather than a date the reader has to subtract:
                // the statutory clock is the whole reason this queue exists.
                'daysLeft' => (int) now()->startOfDay()->diffInDays($subjectRequest->deadline_at->startOfDay(), false),
                'openModules' => (int) ($pending[$subjectRequest->getKey()] ?? 0),
            ]),
            'states' => array_column(DataSubjectRequestState::cases(), 'value'),
            'types' => array_column(DataSubjectRequestType::cases(), 'value'),
            'filters' => [
                ...$this->currentFilters($request, $sort, $perPage),
                'state' => $state,
                'type' => $type,
            ],
        ]);
    }

    public function show(string $subjectRequest, GdprHandlerRegistry $registry): Response
    {
        Gate::authorize(Permission::ViewSubjectRequests->value);

        $model = $this->find($subjectRequest);

        $responses = DataSubjectRequestResponse::query()
            ->where('data_subject_request_id', $model->getKey())
            ->orderBy('module')
            ->get();

        // Modules that registered a handler *after* this request opened have
        // no row of their own. Showing them as missing is the honest answer:
        // the fan-out never addressed them, and a re-dispatch is what fixes
        // it. A ConsentWithdrawal request never seeded any per-module rows
        // in the first place (OpenSubjectRequest gates that loop for this
        // type, ADR-0050 §8) — diffing against the full registry here would
        // falsely claim every module was never addressed, when none was
        // ever owed a response.
        $missing = $model->type === DataSubjectRequestType::ConsentWithdrawal
            ? []
            : array_values(array_diff(
                $registry->moduleNames(),
                $responses->pluck('module')->all(),
            ));

        return Inertia::render('admin/subject-requests/Show', [
            'request' => [
                'id' => $model->getKey(),
                'subjectId' => $model->subject_id,
                'brandName' => Brand::query()->whereKey($model->filed_on_brand_id)->value('name') ?? '—',
                'type' => $model->type->value,
                'state' => $model->state->value,
                'mode' => $model->mode?->value,
                'deadlineAt' => $model->deadline_at->toDateTimeString(),
                'openedAt' => $model->created_at?->toDateTimeString(),
                'closedAt' => $model->closed_at?->toDateTimeString(),
                'reasonCode' => $model->reason_code,
                'archiveExpiresAt' => $model->archive_expires_at?->toDateTimeString(),
                'archiveAvailable' => $model->hasDownloadableArchive(),
            ],
            'responses' => $responses->map(fn (DataSubjectRequestResponse $response): array => [
                'module' => $response->module,
                'state' => $response->state->value,
                'respondedAt' => $response->responded_at?->toDateTimeString(),
            ])->all(),
            'missingModules' => $missing,
            'canHandle' => Gate::allows(Permission::HandleSubjectRequests->value),
            'canDownload' => Gate::allows(Permission::DownloadSubjectExport->value),
        ]);
    }

    /**
     * Re-runs the whole fan-out (`RedispatchSubjectRequest`), not just the
     * modules that failed: Compliance cannot know which of a module's rows a
     * handler already touched, which is why handlers must be idempotent.
     */
    public function redispatch(string $subjectRequest, RedispatchSubjectRequest $redispatch): RedirectResponse
    {
        Gate::authorize(Permission::HandleSubjectRequests->value);

        $model = $this->find($subjectRequest);

        $redispatch($model);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fan-out re-dispatched.')]);

        return back();
    }

    public function refuse(Request $request, string $subjectRequest, RefuseSubjectRequest $refuse): RedirectResponse
    {
        Gate::authorize(Permission::HandleSubjectRequests->value);

        $validated = $request->validate(['reasonCode' => ['required', 'string', 'max:100']]);

        $refuse($this->find($subjectRequest), $validated['reasonCode']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request refused.')]);

        return back();
    }

    /**
     * Accepts a ConsentWithdrawal request: withdraws the named consent
     * through the same write the self-service path uses, without re-entering
     * the lawful-basis guard, and closes the request Fulfilled (ADR-0050
     * §7/§8, controller rulings 1-2). Same capability as refuse — accepting
     * and refusing are the two ways a handler resolves a request.
     */
    public function acceptConsentWithdrawal(string $subjectRequest, ApproveConsentWithdrawal $approve): RedirectResponse
    {
        Gate::authorize(Permission::HandleSubjectRequests->value);

        $approve($this->find($subjectRequest));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Withdrawal accepted.')]);

        return back();
    }

    public function download(string $subjectRequest, AuditRecorder $auditRecorder): StreamedResponse
    {
        Gate::authorize(Permission::DownloadSubjectExport->value);

        $model = $this->find($subjectRequest);

        abort_unless($model->hasDownloadableArchive(), 404);

        // An operator reading one person's entire record across every module
        // is the most privileged read in the application, and it emits no
        // domain event — the explicit recorder is what the hybrid trail has
        // this half for (Round 6 D-BATCH).
        $auditRecorder->record(
            action: 'compliance.subject-export.downloaded',
            subjectId: $model->subject_id,
            brandId: $model->filed_on_brand_id,
            context: ['request_id' => $model->getKey()],
        );

        return Storage::disk((string) $model->archive_disk)
            ->download((string) $model->archive_path, "subject-export-{$model->getKey()}.json");
    }

    private function find(string $subjectRequest): DataSubjectRequest
    {
        /** @var DataSubjectRequest */
        return DataSubjectRequest::query()->findOrFail($subjectRequest);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function filterValue(Request $request, string $key, array $allowed): ?string
    {
        $value = $request->string($key)->toString();

        return in_array($value, $allowed, true) ? $value : null;
    }
}
