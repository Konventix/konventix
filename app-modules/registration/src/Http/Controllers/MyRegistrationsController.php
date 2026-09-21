<?php

declare(strict_types=1);

namespace Konventix\Registration\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Registration\Data\MyRegistrationData;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * The registrant's own registrations — the account-side counterpart to the
 * organizer's console list, and until it existed there was no page anywhere
 * that answered "what did I sign up for".
 *
 * Authorization is ownership, checked here rather than by a policy: there is
 * no capability involved, only the question of whose row this is. The brand
 * scope (ADR-0010) narrows to the current brand before that, so a
 * registration made with one brand is invisible on another's host even to
 * the account that made it — which is what ADR-0016's one-account-many-brands
 * means from the outside.
 */
final class MyRegistrationsController
{
    public function __construct(private readonly SlotRegistry $slots) {}

    /**
     * The list, without the cancellations unless they are asked for
     * (GitLab #239).
     *
     * A cancelled registration is history: there is nothing on it the
     * registrant can act on, and an account that registered three times and
     * cancelled twice opened this page on two dead rows. The choice lives in
     * the query string rather than in component state so a reload — or a
     * link someone sends themselves — keeps it, the same reason the console's
     * list filters live in the URL.
     *
     * `cancelledCount` is sent whichever way the toggle stands: the page
     * needs it to label the toggle, and to tell "you have not registered for
     * anything" apart from "everything you registered for was cancelled",
     * which are the same empty list and not the same sentence.
     */
    public function index(Request $request): Response
    {
        $registrantId = (string) $request->user()->getAuthIdentifier();
        $showAll = $request->query('show') === 'all';

        $query = Registration::query()->where('registrant_user_id', $registrantId);

        if (! $showAll) {
            $query->where('status', '!=', RegistrationStatus::Cancelled);
        }

        $registrations = $query->orderByDesc('submitted_at')->get();

        return Inertia::render('registration/my/Index', [
            'registrations' => $this->present($registrations),
            'showAll' => $showAll,
            'cancelledCount' => Registration::query()
                ->where('registrant_user_id', $registrantId)
                ->where('status', RegistrationStatus::Cancelled)
                ->count(),
        ]);
    }

    public function show(Request $request, Registration $registration): Response
    {
        abort_unless(
            $registration->registrant_user_id === (string) $request->user()->getAuthIdentifier(),
            403,
        );

        $type = RegistrationType::query()->find($registration->registration_type_id);

        return Inertia::render('registration/my/Show', [
            'registration' => $this->present(Collection::make([$registration]))[0],
            // The billing block only renders when there is one: a free
            // registration was never asked for an address, and an empty
            // "Billing" heading is an invitation to wonder what is missing.
            'billing' => $registration->billing_recipient_name === null ? null : [
                'recipientName' => $registration->billing_recipient_name,
                'email' => $registration->billing_email,
                'addressLine' => $registration->billing_address_line,
                'postalCode' => $registration->billing_postal_code,
                'city' => $registration->billing_city,
                'countryCode' => $registration->billing_country_code,
                'vatId' => $registration->billing_vat_id,
            ],
            // What this registration entitles its holder to, in the words the
            // type declared them (ADR-0005). Quantities, not admission
            // tokens: those are Admission's, and it has its own surfaces.
            'grants' => $type === null ? [] : $type->grants->toCollection()
                ->map(fn ($grant): array => [
                    'kind' => $grant->kind->value,
                    'role' => $grant->role,
                    'quantity' => $grant->quantity,
                ])
                ->all(),
            // What it cost and whether it is settled — Commerce's, through
            // the `registration.mine.show` slot. This module knows the row
            // is the reader's, having just checked; Commerce knows what was
            // charged for it. Neither reads the other's tables (ADR-0004).
            'slots' => $this->slots->for('registration.mine.show', [
                'registrationId' => $registration->getKey(),
            ])
                ->map(fn (array $entry): array => [
                    'module' => $entry['module'],
                    'component' => $entry['component'],
                    'props' => $entry['props'],
                ])
                ->all(),
        ]);
    }

    /**
     * @param  Collection<int, Registration>  $registrations
     * @return array<int, array<string, mixed>>
     */
    private function present(Collection $registrations): array
    {
        // Two lookups for the whole page rather than two per row: the names
        // live in this module's own read model and in its own type table,
        // and the list is short enough that a keyed fetch beats a join. The
        // summary's slug travels with its name so the page can link the
        // event (Task 17c); a registration whose summary has not arrived
        // renders the name fallback with no link.
        $summaries = EventSummary::query()
            ->whereIn('event_id', $registrations->pluck('event_id')->unique())
            ->get(['event_id', 'name', 'slug'])
            ->keyBy('event_id');

        $typeNames = RegistrationType::query()
            ->whereIn('id', $registrations->pluck('registration_type_id')->unique())
            ->pluck('name', 'id');

        return $registrations
            ->map(fn (Registration $registration): array => MyRegistrationData::ofModel(
                $registration,
                $summaries->get($registration->event_id)?->name,
                $typeNames->get($registration->registration_type_id),
                $summaries->get($registration->event_id)?->slug,
            )->toArray())
            ->all();
    }
}
