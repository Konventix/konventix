<?php

declare(strict_types=1);

namespace Konventix\Registration\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Compliance\Audit\AuditRecorder;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Registration\Actions\ApproveRegistration;
use Konventix\Registration\Enums\Permission;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Registrant;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
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

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ViewRegistrations->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['submitted_at', 'status', 'payment_status'], default: 'submitted_at');

        if (! $request->filled('sort')) {
            $sort['direction'] = 'desc';
        }

        $registrations = $this->filteredQuery($request)
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
                    'submittedAt' => $registration->submitted_at->format('Y-m-d H:i'),
                    'approvable' => $registration->status === RegistrationStatus::Submitted,
                ];
            }),
            'filters' => [
                ...$this->currentFilters($request, $sort, $perPage),
                'status' => $request->string('status')->toString() ?: null,
                'event' => $request->string('event')->toString() ?: null,
            ],
        ]);
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
    public function export(Request $request, AuditRecorder $auditRecorder): StreamedResponse
    {
        Gate::authorize(Permission::ExportRegistrations->value);

        $query = $this->filteredQuery($request)->orderBy('submitted_at');

        // The audit trail's explicit half (Round 6 D-BATCH): pulling every
        // registrant's name and email out of the system changes nothing, so
        // there is no domain event to subscribe to — and it is exactly the
        // kind of access worth having a record of. Recorded before the stream
        // starts, because a download that dies halfway still happened.
        $auditRecorder->record(
            action: 'registration.registrations.exported',
            context: [
                'event' => $request->string('event')->toString() ?: null,
                'status' => $request->string('status')->toString() ?: null,
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
     * @return Builder<Registration>
     */
    private function filteredQuery(Request $request)
    {
        return Registration::withoutBrandScope()
            ->when($request->filled('event'), fn ($query) => $query->where('event_id', $request->string('event')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
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
