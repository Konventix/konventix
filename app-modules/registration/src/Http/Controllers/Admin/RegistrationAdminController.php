<?php

declare(strict_types=1);

namespace Konventix\Registration\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Compliance\Audit\AuditRecorder;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Platform\Operators\ConsoleSelection;
use Konventix\Registration\Actions\ApproveRegistration;
use Konventix\Registration\Actions\DecideEntryConditionAssertion;
use Konventix\Registration\Actions\ReduceRegistrationQuantity;
use Konventix\Registration\Data\DecideEntryConditionData;
use Konventix\Registration\Data\EntryConditionAssertionData;
use Konventix\Registration\Data\ReduceRegistrationQuantityData;
use Konventix\Registration\Enums\EntryConditionAssertionState;
use Konventix\Registration\Enums\EntryConditionReasonCode;
use Konventix\Registration\Enums\Permission;
use Konventix\Registration\Enums\QuantityReductionReason;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\EntryConditionAssertion;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\Registrant;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Operator console: the organizer-side registration list (SPEC.md "The
 * approval workflow"; TODO.md "Organizer-side registration list with
 * filters, bulk approval, and export"). Registrant display data comes from
 * this module's own read model, never Identity's table (ADR-0004).
 *
 * Registrations are brand-owned; the admin host hardens `BrandScope`, so
 * every query is explicitly `withoutBrandScope()` (ADR-0010/ADR-0029).
 */
class RegistrationAdminController
{
    use HasDataTableQuery;

    public function index(Request $request, ConsoleSelection $selection): Response
    {
        Gate::authorize(Permission::ViewRegistrations->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['submitted_at', 'status', 'payment_status'], default: 'submitted_at');

        if (! $request->filled('sort')) {
            $sort['direction'] = 'desc';
        }

        $filters = $this->declaredFilters($request, $selection);

        $registrations = $this->filteredQuery($request, $filters)
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        $registrants = Registrant::query()
            ->whereIn('id', $registrations->getCollection()->pluck('registrant_user_id'))
            ->get()
            ->keyBy('id');

        return Inertia::render('admin/registrations/Index', [
            'registrations' => $registrations->through(function (Registration $registration) use ($registrants): array {
                /** @var Registrant|null $registrant */
                $registrant = $registrants->get($registration->registrant_user_id);

                return [
                    'id' => $registration->getKey(),
                    'eventId' => $registration->event_id,
                    'registrant' => $registrant === null ? $registration->registrant_user_id : $registrant->display_name,
                    'registrantEmail' => $registrant === null ? null : $registrant->email,
                    'organization' => $registration->organization_name,
                    'status' => $registration->status->value,
                    'paymentStatus' => $registration->payment_status->value,
                    // ISO 8601 with the offset, the same as this controller's
                    // own CSV export below: a system instant (ADR-0027) that
                    // the page shows in the reader's zone.
                    'submittedAt' => $registration->submitted_at->toIso8601String(),
                    'approvable' => $registration->status === RegistrationStatus::Submitted,
                    // D-REG-15: how many units the act buys, and whether any
                    // of them can be given back. `quantity > 1` is the whole
                    // rule — removing the last unit is a cancellation, which
                    // `CancelRegistration` owns.
                    'quantity' => $registration->quantity,
                    'reducible' => $registration->status === RegistrationStatus::Confirmed
                        && $registration->quantity > 1,
                ];
            }),
            'filters' => $this->currentFilters($request, $sort, $perPage, $filters),
            'statuses' => $this->statusValues(),
            'conditionStates' => $this->conditionFilterValues(),
            'brands' => $this->brandOptions(),
            'events' => $this->eventOptions($filters['brand']),
            // The capability, not the row state: a reader without it never
            // sees the button, and the route authorizes again regardless.
            'canReduceQuantity' => Gate::allows(Permission::ReduceRegistrationQuantity->value),
            'canVerifyEntryConditions' => Gate::allows(Permission::VerifyEntryConditions->value),
            'reductionReasons' => array_map(
                fn (QuantityReductionReason $case): string => $case->value,
                QuantityReductionReason::cases(),
            ),
        ]);
    }

    /**
     * One registration, and the declarations it rests on (spec §9.1).
     *
     * The list is the union of three things, because D-REG-19 left the set
     * of conditions editable after a type has sold: what this registration
     * declared, what the type demands that it never declared (*not
     * asserted*, no row — the absence is the state), and what it declared
     * for a condition the type has since dropped (*no longer required*, the
     * row stays because it is evidence).
     *
     * Fetched by hand rather than route-model-bound, and escaped per
     * relation: the admin host resolves no brand and `BrandScope` hardens
     * every brand-owned query — the parent, the pivot's related model and
     * the assertions alike — to zero rows (ADR-0029, .ai/rules).
     */
    public function show(string $registration): Response
    {
        Gate::authorize(Permission::ViewRegistrations->value);

        /** @var Registration $model */
        $model = Registration::withoutBrandScope()->findOrFail($registration);

        /** @var RegistrationType|null $type */
        $type = RegistrationType::withoutBrandScope()->find($model->registration_type_id);

        $demanded = $type === null
            ? collect()
            : $type->entryConditions()->withoutGlobalScope(BrandScope::class)->get()->keyBy('id');

        $assertions = EntryConditionAssertion::withoutBrandScope()
            ->where('registration_id', $model->getKey())
            ->with(['entryCondition' => fn ($query) => $query->withoutGlobalScope(BrandScope::class)])
            ->get();

        $rows = $assertions
            ->map(fn (EntryConditionAssertion $assertion): EntryConditionAssertionData => EntryConditionAssertionData::ofAssertion(
                $assertion,
                $assertion->entryCondition,
                stillRequired: $demanded->has($assertion->entry_condition_id),
            ))
            ->concat($demanded
                ->reject(fn (EntryCondition $condition): bool => $assertions->contains('entry_condition_id', $condition->getKey()))
                ->map(fn (EntryCondition $condition): EntryConditionAssertionData => EntryConditionAssertionData::ofUnasserted($condition)))
            ->values()
            ->all();

        /** @var Registrant|null $registrant */
        $registrant = Registrant::query()->find($model->registrant_user_id);

        return Inertia::render('admin/registrations/Show', [
            'registration' => [
                'id' => (string) $model->getKey(),
                'registrant' => $registrant->display_name ?? $model->registrant_user_id,
                'registrantEmail' => $registrant->email ?? null,
                'organization' => $model->organization_name,
                'typeName' => $type->name ?? $model->registration_type_id,
                'eventName' => EventSummary::withoutBrandScope()
                    ->where('event_id', $model->event_id)
                    ->value('name') ?? $model->event_id,
                'status' => $model->status->value,
                'paymentStatus' => $model->payment_status->value,
                'quantity' => $model->quantity,
                'submittedAt' => $model->submitted_at->toIso8601String(),
            ],
            'entryConditions' => $rows,
            // The capability, not the row state: a reader without it never
            // sees the controls, and the route authorizes again regardless.
            'canVerifyEntryConditions' => Gate::allows(Permission::VerifyEntryConditions->value),
            'reasonCodes' => array_map(
                fn (EntryConditionReasonCode $case): string => $case->value,
                EntryConditionReasonCode::cases(),
            ),
        ]);
    }

    /**
     * Verify, refuse or waive one declaration (spec §9.1, D-REG-22) behind
     * `registration.verify-entry-conditions`.
     *
     * Advisory throughout: a refusal records a fact, and it neither cancels
     * the registration nor revokes an admission nor reaches Commerce (T-8,
     * ADR-0005). An operator who wants a blocking check sets
     * `approval: required` on the type.
     */
    public function decideEntryCondition(
        string $registration,
        string $entryCondition,
        DecideEntryConditionData $data,
        DecideEntryConditionAssertion $decide,
        Request $request,
    ): RedirectResponse {
        Gate::authorize(Permission::VerifyEntryConditions->value);

        /** @var Registration $model */
        $model = Registration::withoutBrandScope()->findOrFail($registration);

        /** @var EntryCondition $condition */
        $condition = EntryCondition::withoutBrandScope()
            ->where('brand_id', $model->brand_id)
            ->findOrFail($entryCondition);

        try {
            $decide->handle(
                registration: $model,
                condition: $condition,
                state: $data->state,
                decidedByUserId: (string) $request->user()?->getKey(),
                reasonCode: $data->reasonCode,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['state' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Declaration recorded as :state.', ['state' => $data->state->value])]);

        return back();
    }

    /**
     * Partial cancellation (D-REG-15): `units` off a confirmed
     * registration's `quantity`, the quota units back, and — through
     * `RegistrationQuantityReduced` — the admissions revoked and the money
     * for exactly those units returned.
     *
     * The registration is fetched by hand rather than route-model-bound:
     * the admin host resolves no brand and `BrandScope` hardens every
     * brand-owned query to zero rows (ADR-0029), so implicit binding would
     * 404 every existing row. The Action then reads the type through
     * `LoadsRegistrationType`, which escapes the scope per relation (#236).
     *
     * Every refusal — Admission's included, since its listener runs inside
     * the Action's transaction — arrives here as a `RuntimeException` and
     * becomes a validation message on the dialog. Nothing partial can
     * survive one: the transaction rolled back, so there is no reduction
     * row, no released quota and no queued refund.
     */
    public function reduceQuantity(
        string $registration,
        ReduceRegistrationQuantityData $data,
        ReduceRegistrationQuantity $reduceRegistrationQuantity,
        AuditRecorder $auditRecorder,
        Request $request,
    ): RedirectResponse {
        Gate::authorize(Permission::ReduceRegistrationQuantity->value);

        /** @var Registration $model */
        $model = Registration::withoutBrandScope()->findOrFail($registration);

        $previousQuantity = $model->quantity;

        try {
            $reduction = $reduceRegistrationQuantity->handle(
                registration: $model,
                units: $data->units,
                reasonCode: $data->reasonCode->value,
                reasonNote: $data->reasonNote,
                reducedByUserId: (string) $request->user()?->getKey(),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['units' => $e->getMessage()]);
        }

        // The explicit half of the audit trail (Round 6, D-BATCH), the same
        // call the refund console makes: `RegistrationQuantityReduced` is no
        // `AuditableEvent`, so nothing else would record *who* shrank a
        // paid-for registration. After the Action, never inside it — an
        // audit write must not take part in a rollback.
        $auditRecorder->record(
            action: 'registration.quantity-reduced',
            subjectId: $model->getKey(),
            brandId: $model->brand_id,
            context: [
                'reduction_id' => $reduction->id,
                'units' => $reduction->units,
                'previous_quantity' => $previousQuantity,
                'new_quantity' => $previousQuantity - $reduction->units,
                'reason_code' => $reduction->reason_code,
                'reason_note' => $reduction->reason_note,
            ],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':units unit(s) removed; the money for them is on its way back.', [
                'units' => $reduction->units,
            ]),
        ]);

        return back();
    }

    /**
     * Bulk approval (TODO.md): each id runs through `ApproveRegistration`
     * individually — the Action owns the guards, so a mixed selection
     * approves what it legally can and reports the rest.
     */
    public function bulkApprove(Request $request, ApproveRegistration $approveRegistration): RedirectResponse
    {
        Gate::authorize(Permission::ApproveRegistrations->value);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['ulid'],
        ]);

        $approved = 0;
        $skipped = 0;

        foreach ($validated['ids'] as $id) {
            /** @var Registration|null $registration */
            $registration = Registration::withoutBrandScope()->find($id);

            if ($registration === null) {
                $skipped++;

                continue;
            }

            // BrandScope hardens to nothing on the admin host (.ai/rules):
            // the Action's loadMissing('registrationType') would read null,
            // so the relation is pre-set from an unscoped lookup.
            $registration->setRelation(
                'registrationType',
                RegistrationType::withoutBrandScope()->find($registration->registration_type_id),
            );

            try {
                $approveRegistration->handle($registration, (string) $request->user()?->getKey());
                $approved++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        Inertia::flash('toast', [
            'type' => $skipped === 0 ? 'success' : 'info',
            'message' => __(':approved approved, :skipped skipped.', ['approved' => $approved, 'skipped' => $skipped]),
        ]);

        return back();
    }

    /**
     * CSV export of the current filter set (TODO.md). Streams — an
     * exhibitor list is small, a visitor list may not be.
     */
    public function export(Request $request, AuditRecorder $auditRecorder, ConsoleSelection $selection): StreamedResponse
    {
        Gate::authorize(Permission::ExportRegistrations->value);

        $filters = $this->declaredFilters($request, $selection);

        $query = $this->filteredQuery($request, $filters)->orderBy('submitted_at');

        // The audit trail's explicit half (Round 6 D-BATCH): pulling every
        // registrant's name and email out of the system changes nothing, so
        // there is no domain event to subscribe to — and it is exactly the
        // kind of access worth having a record of. Recorded before the stream
        // starts, because a download that dies halfway still happened.
        $auditRecorder->record(
            action: 'registration.registrations.exported',
            context: [
                'brand' => $filters['brand'],
                'event' => $filters['event'],
                'status' => $filters['status'],
                'search' => $request->string('search')->toString() ?: null,
                'rows' => (clone $query)->count(),
            ],
        );

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, ['id', 'event_id', 'registrant', 'email', 'organization', 'status', 'payment_status', 'submitted_at']);

            $query->chunk(200, function ($chunk) use ($out): void {
                $registrants = Registrant::query()
                    ->whereIn('id', $chunk->pluck('registrant_user_id'))
                    ->get()
                    ->keyBy('id');

                foreach ($chunk as $registration) {
                    /** @var Registration $registration */
                    /** @var Registrant|null $registrant */
                    $registrant = $registrants->get($registration->registrant_user_id);

                    fputcsv($out, [
                        $registration->getKey(),
                        $registration->event_id,
                        $registrant === null ? $registration->registrant_user_id : $registrant->display_name,
                        $registrant === null ? '' : $registrant->email,
                        $registration->organization_name ?? '',
                        $registration->status->value,
                        $registration->payment_status->value,
                        $registration->submitted_at->toIso8601String(),
                    ]);
                }
            });

            fclose($out);
        }, 'registrations.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * The list's declared filters (P3, `HasDataTableQuery`): the status
     * enum's own cases are the closed set of allowed values. Both the list
     * and the export read this one declaration, so a value the enum does not
     * know never reaches the query from either.
     *
     * `brand` and `event` default to whatever the operator picked in the
     * console shell (ADR-0029) — a default, not a scope, cleared per list
     * with `brand=all` / `event=all`.
     *
     * The two defaults are not independent: the selection's event belongs to
     * the selection's brand, so a query string naming a different brand must
     * not still inherit it. Defaulting both would filter
     * `brand=Y AND event=E` and return nothing, with the event Select blank
     * because `eventOptions('Y')` never contains E. So the event defaults
     * from the selection only while the brand filter still agrees with it.
     *
     * @return array<string, string|null>
     */
    private function declaredFilters(Request $request, ConsoleSelection $selection): array
    {
        $brand = $this->resolveSelectionFilter($request, 'brand', $selection->brandId());
        $eventDefault = ($brand === null || $brand === $selection->brandId()) ? $selection->eventId() : null;

        return [
            ...$this->resolveFilters($request, [
                'status' => $this->statusValues(),
                // D-REG-20/22: the entry-condition state. For an
                // `operator_checked` condition this filter **is** the
                // worklist — `asserted` is the undecided pile — and it is
                // never a gate: nothing here changes what may be confirmed.
                'condition' => $this->conditionFilterValues(),
            ]),
            'brand' => $brand,
            'event' => $this->resolveSelectionFilter($request, 'event', $eventDefault),
        ];
    }

    /**
     * The event filter's options, from this module's own read model — never
     * Event's tables (ADR-0004). Narrowed by the brand filter, so picking a
     * brand does not leave another brand's events on offer.
     *
     * @return list<array{id: string, name: string}>
     */
    private function eventOptions(?string $brand): array
    {
        return array_values(EventSummary::withoutBrandScope()
            ->when($brand !== null, fn ($query) => $query->forBrand($brand))
            ->orderBy('name')
            ->get(['event_id', 'name'])
            ->map(fn (EventSummary $summary): array => [
                'id' => $summary->event_id,
                'name' => $summary->name,
            ])
            ->all());
    }

    /**
     * The entry-condition filter's closed set: the four assertion states
     * plus `none` for "declared nothing at all" (spec §9.1).
     *
     * @return list<string>
     */
    private function conditionFilterValues(): array
    {
        return [
            ...array_map(fn (EntryConditionAssertionState $case): string => $case->value, EntryConditionAssertionState::cases()),
            'none',
        ];
    }

    /**
     * @return list<string>
     */
    private function statusValues(): array
    {
        return array_map(fn (RegistrationStatus $case): string => $case->value, RegistrationStatus::cases());
    }

    /**
     * @param  array<string, string|null>  $filters
     * @return Builder<Registration>
     */
    private function filteredQuery(Request $request, array $filters)
    {
        return Registration::withoutBrandScope()
            ->when($filters['brand'] !== null, fn ($query) => $query->forBrand($filters['brand']))
            ->when($filters['event'] !== null, fn ($query) => $query->where('event_id', $filters['event']))
            ->when($filters['status'] !== null, fn ($query) => $query->where('status', $filters['status']))
            // The declaration's state, not the registration's: a row matches
            // when *any* of its assertions is in that state. `none` is the
            // other half of the worklist — the registrations that declared
            // nothing at all.
            ->when($filters['condition'] === 'none', fn ($query) => $query->whereDoesntHave(
                'entryConditionAssertions',
                fn ($inner) => $inner->withoutGlobalScope(BrandScope::class),
            ))
            ->when(
                $filters['condition'] !== null && $filters['condition'] !== 'none',
                fn ($query) => $query->whereHas(
                    'entryConditionAssertions',
                    fn ($inner) => $inner->withoutGlobalScope(BrandScope::class)->where('state', $filters['condition']),
                ),
            )
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('organization_name', 'ilike', $term)
                        ->orWhere('billing_recipient_name', 'ilike', $term)
                        ->orWhere('billing_email', 'ilike', $term)
                        ->orWhereIn('registrant_user_id', Registrant::query()
                            ->where('display_name', 'ilike', $term)
                            ->orWhere('email', 'ilike', $term)
                            ->select('id'));
                });
            });
    }
}
