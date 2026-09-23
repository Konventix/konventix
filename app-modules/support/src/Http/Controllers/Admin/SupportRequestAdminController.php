<?php

declare(strict_types=1);

namespace Konventix\Support\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Compliance\Audit\AuditRecorder;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Support\Actions\AssignSupportRequest;
use Konventix\Support\Actions\MarkKnownIssue;
use Konventix\Support\Actions\PostSupportMessage;
use Konventix\Support\Actions\SetExternalReference;
use Konventix\Support\Actions\TransitionSupportRequest;
use Konventix\Support\Data\SupportAssigneeInputData;
use Konventix\Support\Data\SupportExternalReferenceInputData;
use Konventix\Support\Data\SupportInboxRowData;
use Konventix\Support\Data\SupportKnownIssueInputData;
use Konventix\Support\Data\SupportMessageInputData;
use Konventix\Support\Data\SupportRequestDetailData;
use Konventix\Support\Data\SupportRequestMessageData;
use Konventix\Support\Data\SupportStateInputData;
use Konventix\Support\Enums\Permission;
use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Exceptions\InvalidSupportTransition;
use Konventix\Support\Links\SupportLinks;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;
use Konventix\Support\Queries\TriageHolders;

/**
 * The operator console's support inbox and one report's detail page
 * (design §9).
 *
 * **No `withoutBrandScope()` anywhere, and that absence is the design.**
 * Every other console controller in this codebase escapes `BrandScope` on
 * every query, because the admin host resolves no brand and the scope
 * hardens brand-owned reads to `WHERE 1=0` (ADR-0029, `.ai/rules`).
 * `SupportRequest` carries no `BelongsToBrand` at all — the one model in
 * the codebase that deliberately does not (S0, ADR-0050 §1) — precisely so
 * this inbox can list every brand's reports *and* the brandless ones filed
 * on the console itself. `filed_on_brand_id` is therefore a plain column
 * this controller filters on by hand.
 *
 * Reading is `support.view-requests`; every write is
 * `support.triage-requests`; the reporter's unmasked contact is
 * `support.view-reporter-contact` and is audited on every disclosure
 * (§10.1, §10.8). The three are separate because triaging a report and
 * learning who sent it are different acts with different risks.
 *
 * Each write is one route, one Action, one audit entry. Three of the five
 * carry a domain event that is itself an `AuditableEvent`, so Compliance's
 * wildcard subscriber records them; assignment and the external reference
 * have no event to emit — nothing outside the module could act on either,
 * and §10.4 fixes the public contract at four events — so this controller
 * records them explicitly through `AuditRecorder`, after the Action, where
 * an audit write can never take part in a rollback (Round 6, D-BATCH).
 */
final class SupportRequestAdminController
{
    use HasDataTableQuery;

    /** The inbox's default view: everything somebody still owes an answer. */
    private const string OPEN_FILTER = 'open';

    /** The brand filter's value for a report filed on the console itself. */
    private const string CONSOLE_FILTER = 'console';

    /**
     * The inbox (design §9.1), newest first.
     *
     * The state filter defaults to `open` rather than to nothing: an inbox
     * whose first screen is last month's resolved reports is not an inbox.
     * Clearing it sends the literal `all`, the convention
     * `HasDataTableQuery::resolveSelectionFilter()` established, so the
     * cleared view survives the next sort instead of snapping back.
     *
     * The brand filter deliberately does **not** default from the console
     * selection the way Registration's does. A support inbox is read across
     * brands — that is the whole reason the model is not brand-scoped — and
     * a report filed on the console belongs to no brand at all, which a
     * selection could not express.
     */
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ViewRequests->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['created_at', 'state'], default: 'created_at');

        if (! $request->filled('sort')) {
            $sort['direction'] = 'desc';
        }

        $brands = Brand::query()->orderBy('name')->get(['id', 'name']);

        $filters = [
            'state' => $this->resolveStateFilter($request),
            ...$this->resolveFilters($request, [
                'category' => SupportCategory::values(),
                'brand' => [self::CONSOLE_FILTER, ...array_map(strval(...), array_values($brands->pluck('id')->all()))],
                'known_issue' => ['yes', 'no'],
            ]),
        ];

        $requests = $this->filteredQuery($request, $filters)
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        $brandNames = $brands->pluck('name', 'id');

        return Inertia::render('admin/support-requests/Index', [
            'requests' => $requests->through(fn (SupportRequest $row): SupportInboxRowData => SupportInboxRowData::ofRequest(
                $row,
                $this->brandLabel($row->filed_on_brand_id, $brandNames->all()),
            )),
            'filters' => $this->currentFilters($request, $sort, $perPage, $filters),
            'states' => SupportRequestState::values(),
            'categories' => SupportCategory::values(),
            'brands' => $brands->map(fn (Brand $brand): array => [
                'id' => (string) $brand->getKey(),
                'name' => $brand->name,
            ])->all(),
            'canTriage' => Gate::allows(Permission::TriageRequests->value),
        ]);
    }

    /**
     * One report (design §9.2).
     *
     * Opening it as the first staff member is what moves `received → seen`,
     * through the same Action the buttons use — so the move is audited, the
     * metric counts it, and `support_requests_unseen_oldest_age_seconds`
     * stops running for this report. `TransitionSupportRequest` treats a
     * repeat as a no-op, so the second and hundredth open emit nothing.
     *
     * The unmasked contact is fetched only for a holder, and recording the
     * disclosure happens in the same breath as fetching it: a page that
     * showed the address without the audit entry would be exactly the
     * failure `support.view-reporter-contact` exists to prevent (§10.8).
     */
    public function show(
        string $supportRequest,
        Request $request,
        TransitionSupportRequest $transition,
        AuditRecorder $auditRecorder,
        SupportLinks $links,
        TriageHolders $triageHolders,
    ): Response {
        Gate::authorize(Permission::ViewRequests->value);

        /** @var SupportRequest $model */
        $model = SupportRequest::query()->findOrFail($supportRequest);

        if ($model->state === SupportRequestState::Received) {
            $transition->handle($model, SupportRequestState::Seen, $this->actorId($request));
        }

        $messages = array_values(SupportRequestMessage::query()
            ->where('support_request_id', $model->getKey())
            ->orderBy('created_at')
            ->get()
            ->map(SupportRequestMessageData::fromModel(...))
            ->all());

        $context = $model->context;

        return Inertia::render('admin/support-requests/Show', [
            'detail' => SupportRequestDetailData::ofRequest(
                request: $model,
                brandLabel: $this->brandLabel($model->filed_on_brand_id),
                reporterContact: $this->reporterContact($model, $auditRecorder),
                messages: $messages,
                pageLogsUrl: $links->logsUrlFor($context->pageCorrelationId),
                submitLogsUrl: $links->logsUrlFor($context->submitCorrelationId),
            ),
            'assignees' => $triageHolders->forBrand($model->filed_on_brand_id),
            'currentUserId' => $this->actorId($request),
            'canTriage' => Gate::allows(Permission::TriageRequests->value),
        ]);
    }

    /**
     * `PATCH …/state`. A move the table of §4.3 forbids is a refusal, not a
     * crash: `TransitionSupportRequest` throws, and it becomes a 422 on the
     * `state` field — the same shape Registration's console gives a refused
     * Action, so the page can show it beside the control that asked.
     *
     * It happens for one honest reason: two operators acting on the same
     * report at once. The page only ever offers `allowedNext()`.
     */
    public function updateState(
        string $supportRequest,
        SupportStateInputData $data,
        Request $request,
        TransitionSupportRequest $transition,
    ): RedirectResponse {
        Gate::authorize(Permission::TriageRequests->value);

        $model = $this->findForWrite($supportRequest);

        try {
            $transition->handle($model, $data->state, $this->actorId($request));
        } catch (InvalidSupportTransition $exception) {
            throw ValidationException::withMessages(['state' => $exception->getMessage()]);
        }

        return $this->done(__('Moved to :state.', ['state' => $data->state->label()]));
    }

    /**
     * `PATCH …/assignee`. Null hands the report back to the pile.
     *
     * The picker offers triage holders, and this checks the same list
     * rather than trusting the id that arrives: a report assigned to
     * somebody who cannot open it is a report nobody is working on.
     */
    public function updateAssignee(
        string $supportRequest,
        SupportAssigneeInputData $data,
        Request $request,
        AssignSupportRequest $assign,
        AuditRecorder $auditRecorder,
        TriageHolders $triageHolders,
    ): RedirectResponse {
        Gate::authorize(Permission::TriageRequests->value);

        $model = $this->findForWrite($supportRequest);
        $assignee = $data->assignedToUserId;

        if ($assignee !== null && ! in_array($assignee, $triageHolders->forBrand($model->filed_on_brand_id), true)) {
            throw ValidationException::withMessages([
                'assignedToUserId' => __('That account cannot triage reports here.'),
            ]);
        }

        $previous = $model->assigned_to_user_id;

        $assign->handle($model, $assignee);

        $auditRecorder->record(
            action: 'support.request.assigned',
            subjectId: (string) $model->getKey(),
            brandId: $model->filed_on_brand_id,
            context: ['from' => $previous, 'to' => $assignee],
        );

        return $this->done($assignee === null ? __('Assignment cleared.') : __('Assigned.'));
    }

    /**
     * `PATCH …/known-issue`. The one write here that changes what an
     * unrelated visitor sees, which is why it carries its own event
     * (`SupportRequestKnownIssueMarked`, an `AuditableEvent`).
     */
    public function updateKnownIssue(
        string $supportRequest,
        SupportKnownIssueInputData $data,
        Request $request,
        MarkKnownIssue $markKnownIssue,
    ): RedirectResponse {
        Gate::authorize(Permission::TriageRequests->value);

        $model = $this->findForWrite($supportRequest);

        $markKnownIssue->handle(
            request: $model,
            isKnownIssue: $data->isKnownIssue,
            notice: $data->knownIssueNotice,
            actorUserId: (string) $this->actorId($request),
        );

        return $this->done($data->isKnownIssue
            ? __('Marked as a known issue; the report dialog will say so.')
            : __('No longer a known issue.'));
    }

    /**
     * `PATCH …/external-reference`. Free text by the SPEC's scope ceiling —
     * a deep link into whatever tracker the operator really works in, never
     * an integration.
     */
    public function updateExternalReference(
        string $supportRequest,
        SupportExternalReferenceInputData $data,
        SetExternalReference $setExternalReference,
        AuditRecorder $auditRecorder,
    ): RedirectResponse {
        Gate::authorize(Permission::TriageRequests->value);

        $model = $this->findForWrite($supportRequest);
        $previous = $model->external_reference;

        $setExternalReference->handle($model, $data->externalReference);

        $auditRecorder->record(
            action: 'support.request.external-reference-set',
            subjectId: (string) $model->getKey(),
            brandId: $model->filed_on_brand_id,
            context: ['from' => $previous, 'to' => $model->external_reference],
        );

        return $this->done(__('External reference saved.'));
    }

    /**
     * `POST …/messages`. An internal note or a reply the reporter will read
     * — the form says which, and `SupportRequestMessagePosted` carries the
     * visibility so S3's reply mail can hang off exactly the external ones.
     */
    public function storeMessage(
        string $supportRequest,
        SupportMessageInputData $data,
        Request $request,
        PostSupportMessage $postSupportMessage,
    ): RedirectResponse {
        Gate::authorize(Permission::TriageRequests->value);

        $model = $this->findForWrite($supportRequest);

        $postSupportMessage->handle(
            request: $model,
            visibility: $data->visibility,
            body: $data->body,
            authorUserId: $this->actorId($request),
        );

        return $this->done($data->visibility->isVisibleToReporter()
            ? __('Reply added to the thread.')
            : __('Internal note added.'));
    }

    /**
     * The inbox's query, filters applied. Split out because the shape is
     * long enough that reading `index()` should not mean reading it.
     *
     * @param  array<string, string|null>  $filters
     * @return Builder<SupportRequest>
     */
    private function filteredQuery(Request $request, array $filters): Builder
    {
        return SupportRequest::query()
            ->when($filters['state'] === self::OPEN_FILTER, fn (Builder $query) => $query->open())
            ->when(
                $filters['state'] !== null && $filters['state'] !== self::OPEN_FILTER,
                fn (Builder $query) => $query->where('state', $filters['state']),
            )
            // The column is a JSON list of backed values, so containment is
            // the question — `whereJsonContains` is what Postgres's `@>`
            // answers, and the five cases are a closed set the filter
            // already validated against.
            ->when(
                $filters['category'] !== null,
                fn (Builder $query) => $query->whereJsonContains('categories', $filters['category']),
            )
            ->when(
                $filters['brand'] === self::CONSOLE_FILTER,
                fn (Builder $query) => $query->whereNull('filed_on_brand_id'),
            )
            ->when(
                $filters['brand'] !== null && $filters['brand'] !== self::CONSOLE_FILTER,
                fn (Builder $query) => $query->where('filed_on_brand_id', $filters['brand']),
            )
            ->when(
                $filters['known_issue'] !== null,
                fn (Builder $query) => $query->where('is_known_issue', $filters['known_issue'] === 'yes'),
            )
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';

                // The reference is the last eight characters of the key, so
                // the id is what a reference search matches. The reporter's
                // address is deliberately not searchable: finding people by
                // address is not triage, and `view-reporter-contact` exists
                // to make every such look-up a recorded one.
                $query->where(fn (Builder $nested) => $nested
                    ->where('id', 'ilike', $term)
                    ->orWhere('message', 'ilike', $term)
                    ->orWhere('external_reference', 'ilike', $term));
            });
    }

    /**
     * Absent → the open pile; the literal `all` → no state filter at all;
     * anything else → that state, or the open pile when it is not one.
     */
    private function resolveStateFilter(Request $request): ?string
    {
        if (! $request->has('state')) {
            return self::OPEN_FILTER;
        }

        $value = $request->string('state')->toString();

        if ($value === '' || $value === 'all') {
            return null;
        }

        return in_array($value, [self::OPEN_FILTER, ...SupportRequestState::values()], true)
            ? $value
            : self::OPEN_FILTER;
    }

    /**
     * The brand host a report arrived on, or `Console` for one filed on the
     * operator host — the word the metric label and the context snapshot
     * use (`SupportContextData::CONSOLE_BRAND`), shown in the reader's
     * language (ADR-0068).
     *
     * @param  array<string, string>|null  $names  pre-fetched names, for the list; null fetches the one
     */
    private function brandLabel(?string $brandId, ?array $names = null): string
    {
        if ($brandId === null) {
            return __('Console');
        }

        if ($names !== null) {
            return $names[$brandId] ?? $brandId;
        }

        return Brand::query()->whereKey($brandId)->value('name') ?? $brandId;
    }

    /**
     * The unmasked reporter, for a holder of `support.view-reporter-contact`
     * only — and recorded every single time (§10.8).
     *
     * An account is named by its ULID rather than its display name: that
     * name lives in Identity and this module may not read it (ADR-0004),
     * the same reason Platform's own role editor prints ULIDs.
     */
    private function reporterContact(SupportRequest $request, AuditRecorder $auditRecorder): ?string
    {
        if (! Gate::allows(Permission::ViewReporterContact->value)) {
            return null;
        }

        $contact = $request->email ?? $request->user_id;

        if ($contact === null) {
            return null;
        }

        $auditRecorder->record(
            action: 'support.reporter-contact.viewed',
            subjectId: (string) $request->getKey(),
            brandId: $request->filed_on_brand_id,
            context: ['kind' => $request->email !== null ? 'email' : 'account'],
        );

        return $contact;
    }

    private function findForWrite(string $supportRequest): SupportRequest
    {
        /** @var SupportRequest $model */
        $model = SupportRequest::query()->findOrFail($supportRequest);

        return $model;
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
