<?php

declare(strict_types=1);

namespace Konventix\Resources\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Admission\Events\AdmissionHolderUnassigned;
use Konventix\Admission\Events\AdmissionIssued;
use Konventix\Admission\Events\AdmissionOwnershipAnnounced;
use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Registration\Events\RegistrationAmended;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationQuantityReduced;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Resources\Actions\FindSeatClaimForAdmission;
use Konventix\Resources\Actions\HasFreePlaceForClaim;
use Konventix\Resources\Console\AssignPlaceCommand;
use Konventix\Resources\Console\ExpireResourceHoldsCommand;
use Konventix\Resources\Console\PlanExportCommand;
use Konventix\Resources\Console\PlanImportCommand;
use Konventix\Resources\Console\ProbeSeatRace;
use Konventix\Resources\Data\AdmissionSeatRowData;
use Konventix\Resources\Data\CategoryData;
use Konventix\Resources\Data\SeatSummaryData;
use Konventix\Resources\Data\TicketSeatActionData;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\Permission;
use Konventix\Resources\Events\PlanSaved;
use Konventix\Resources\Events\PlanSaveRefused;
use Konventix\Resources\Events\ResourceBookingConfirmed;
use Konventix\Resources\Events\ResourceBookingHeld;
use Konventix\Resources\Events\ResourceBookingRefused;
use Konventix\Resources\Events\ResourceBookingReleased;
use Konventix\Resources\Gdpr\ResourcesGdprHandler;
use Konventix\Resources\Listeners\FulfilClaimsOnRegistrationAmended;
use Konventix\Resources\Listeners\FulfilClaimsOnRegistrationConfirmed;
use Konventix\Resources\Listeners\Metrics\RecordBookingMetrics;
use Konventix\Resources\Listeners\Metrics\RecordPlanMetrics;
use Konventix\Resources\Listeners\ProjectAdmission;
use Konventix\Resources\Listeners\ProjectEventWindow;
use Konventix\Resources\Listeners\ReduceSeatClaimOnRegistrationQuantityReduced;
use Konventix\Resources\Listeners\ReleaseBookingOnAdmissionRevoked;
use Konventix\Resources\Listeners\ReleaseBookingsOnRegistrationCancelled;
use Konventix\Resources\Listeners\ReleaseBookingsOnRegistrationRejected;
use Konventix\Resources\Metrics\ResourcesGauges;
use Konventix\Resources\Metrics\ResourcesMetrics;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceAdmission;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Resources is Pennant-gated: a brand that books nothing switches it off and
 * pays for none of it (ADR-0003).
 *
 * Three listener kinds, gated three ways on purpose. Claim fulfilment
 * short-circuits when the module is off for the brand the payload names —
 * an inactive module must not create bookings. The event-window projection
 * is never gated: a brand switching Resources on mid-event must find the
 * window already projected. The release listeners on cancellation and
 * rejection are never gated either (ADR-0060's explicit ADR-0003
 * exception): a release is safe and idempotent for a brand that switched
 * the module off, and a seat that stays booked for a cancelled
 * registration is the worse failure.
 */
class ResourcesServiceProvider extends ModuleServiceProvider
{
    public static function moduleName(): string
    {
        return 'resources';
    }

    /**
     * `parent::boot()` first — routes, listeners, slots, schedule and the
     * GDPR handler come from the base class hooks; the metric catalogue is
     * the one thing the base class has no hook for (ADR-0059).
     */
    public function boot(): void
    {
        parent::boot();

        $this->registerMetrics();
    }

    /**
     * Resources' metrics (ADR-0059): catalogue entries here, recorders in
     * registerListeners(), gauges in ResourcesGauges. The host never learns
     * these names.
     */
    protected function registerMetrics(): void
    {
        $this->app->make(MetricCatalog::class)->register(...ResourcesMetrics::definitions());

        Prometheus::registerCollectorClasses([ResourcesGauges::class]);
    }

    /**
     * ADR-0045, design §9.1: two starting points per brand. The planner
     * builds and publishes hall plans and may reseat; the desk only books
     * and releases on somebody's behalf. `permissions:sync` materializes
     * both for every brand with the module on and never rewrites a role an
     * operator has since edited.
     *
     * @return list<DefaultRole>
     */
    public function defaultRoles(): array
    {
        return [
            DefaultRole::of(
                'resources.planner',
                'Planner',
                Permission::ViewResources,
                Permission::ManageResourceTypes,
                Permission::ManageResources,
                Permission::ManagePlans,
                Permission::BookOnBehalf,
                Permission::ReleaseBookings,
            ),
            DefaultRole::of(
                'resources.desk',
                'Resource Desk',
                Permission::ViewResources,
                Permission::BookOnBehalf,
                Permission::ReleaseBookings,
            ),
        ];
    }

    /**
     * Design §8.1: the two console screens, in the operator area
     * (ADR-0029). Admin entries carry no brand gate — there is no brand on
     * that host — so the capability is the only filter; the registry
     * applies it at render time.
     */
    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'resources',
                label: 'Categories',
                url: '/admin/resource-types',
                icon: 'layout-grid',
                order: 40,
                permission: Permission::ManageResourceTypes->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'resources',
                label: 'Seating plans',
                url: '/admin/plans',
                icon: 'map-pin',
                order: 41,
                permission: Permission::ManagePlans->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }

    /**
     * Design §9.2 / §9.3: the type editor's seating fields. Registration
     * hands over the slot and `{ eventId }`; what comes back — the event's
     * categories as `CategoryData`, each carrying its **bookable** place
     * count (published, not blocked, not deleted — `Resource::scopeBookable()`,
     * the same figure the category console labels "Bookable places", design
     * §12b / #282) — is this module's business, resolved by closure at
     * render time under the brand the caller set. The count travels beside
     * the category rather than on `CategoryData` itself, because that class
     * also shapes the registrant-facing picker (S5), which has no business
     * showing an operator a quota-sizing number. Module `resources` and no
     * `feature`: the module's own Pennant feature is the gate (§2).
     *
     * `static` on purpose: `SlotRegistry` is a singleton that outlives the
     * request under Octane, and a closure capturing `$this` would pin this
     * provider with it.
     *
     * Slice S5 (design §7.4) adds `registration.mine.show`: Registration
     * hands over a registration id it has already checked belongs to the
     * reader; this closure answers with the claim's state, its place refs
     * and — 2026-09-10 batch amendment, ahead of §12b/#282's quota
     * mechanism — `HasFreePlaceForClaim`'s read of whether the claim's
     * categories currently have anything free. The picker does not read
     * this Action (corrected 2026-09-10, fix-wave item 2, #278): it derives
     * its own "nothing free" banner live from polled availability instead,
     * so the two surfaces answer over different row sets and are not
     * guaranteed to agree — see `HasFreePlaceForClaim`'s docblock. Reads
     * nothing of Registration's (ADR-0004).
     */
    protected function registerSlots(): void
    {
        $slots = $this->app->make(SlotRegistry::class);

        $slots->add(
            slot: 'registration.type-editor.seating-fields',
            module: 'resources',
            component: 'resources/SeatingGrantFields',
            props: static function (array $context): array {
                $types = ResourceType::query()
                    ->where('event_id', $context['eventId'])
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get();

                // Bookable, not raw — the number an operator reads to size a
                // Registration quota, never a count including draft, blocked
                // or deleted places. A grouped count rather than per-type
                // queries, mirroring ResourceTypeAdminController's list.
                $placeCounts = Resource::query()
                    ->bookable()
                    ->whereIn('resource_type_id', $types->pluck('id'))
                    ->selectRaw('resource_type_id, count(*) as aggregate')
                    ->groupBy('resource_type_id')
                    ->pluck('aggregate', 'resource_type_id');

                return [
                    'categories' => $types
                        ->map(fn (ResourceType $type): array => [
                            ...CategoryData::fromModel($type)->toArray(),
                            'bookablePlaces' => (int) ($placeCounts[$type->getKey()] ?? 0),
                        ])
                        ->all(),
                ];
            },
            order: 10,
        );

        // Design §12b/#282: the quota form's category select. A quota is
        // brand-owned but not event-owned (SPEC.md "Owns" — Quota may be
        // referenced by types across more than one event), so this lists
        // every category of the brand rather than one event's — resolved
        // with the quota's own brand in context the same way
        // `QuotaAdminController` resolves the seating-fields slot above,
        // and carrying `eventName` because two categories of the same
        // brand's different events may share a name. No component actually
        // renders this contribution's `component` field; only its `props`
        // feed the console's own native select, the same way the
        // `bookablePlaces` figure above rides beside `CategoryData` without
        // being part of it.
        $slots->add(
            slot: 'registration.quota-form.categories',
            module: 'resources',
            component: 'resources/CategoryOptions',
            props: static function (): array {
                $types = ResourceType::query()->orderBy('name')->get(['id', 'name', 'event_id']);

                $eventNames = ResourceEventWindow::query()
                    ->whereIn('event_id', $types->pluck('event_id')->unique())
                    ->pluck('name', 'event_id');

                return [
                    'categories' => $types
                        ->map(fn (ResourceType $type): array => [
                            'id' => $type->id,
                            'name' => $type->name,
                            'eventName' => $eventNames[$type->event_id] ?? null,
                        ])
                        ->all(),
                ];
            },
            order: 10,
        );

        $slots->add(
            slot: 'registration.mine.show',
            module: 'resources',
            component: 'resources/SeatSummarySlot',
            props: static function (array $context): array {
                $claim = ResourceClaim::query()
                    ->where('registration_id', $context['registrationId'])
                    ->where('state', '!=', ClaimState::Released)
                    ->orderByDesc('created_at')
                    ->first();

                if ($claim === null) {
                    return ['summary' => null];
                }

                $placeIds = ResourceBooking::query()
                    ->where('claim_id', $claim->getKey())
                    ->whereIn('state', BookingState::occupyingValues())
                    ->pluck('resource_id');

                $refs = array_values(Resource::query()
                    ->whereKey($placeIds)
                    ->get(['id', 'ref', 'label'])
                    ->map(fn (Resource $place): string => $place->ref ?? $place->label)
                    ->sort(SORT_NATURAL)
                    ->values()
                    ->all());

                $noFreePlaces = ! app(HasFreePlaceForClaim::class)->handle($claim);

                // Per ticket (#292 §6.4): each admission of the registration
                // and the place bound to it, so the page shows "Ticket 2 —
                // A12" rather than a bag of refs. Numbered in issue order.
                $admissions = ResourceAdmission::query()
                    ->where('registration_id', $claim->registration_id)
                    ->where('state', '!=', ResourceAdmission::STATE_REVOKED)
                    ->orderBy('created_at')
                    ->get();
                $bound = ResourceBooking::query()
                    ->where('claim_id', $claim->getKey())
                    ->whereNotNull('admission_id')
                    ->whereIn('state', BookingState::occupyingValues())
                    ->pluck('resource_id', 'admission_id');
                $boundRefs = Resource::query()
                    ->whereKey($bound->values())
                    ->get(['id', 'ref', 'label'])
                    ->mapWithKeys(fn (Resource $place): array => [$place->id => $place->ref ?? $place->label]);
                $rows = array_values($admissions->values()->map(fn (ResourceAdmission $admission, int $index): AdmissionSeatRowData => new AdmissionSeatRowData(
                    admissionId: $admission->id,
                    number: $index + 1,
                    placeRef: $bound->has($admission->id) ? ($boundRefs->get($bound->get($admission->id)) ?? null) : null,
                    isHeld: $admission->holder_user_id !== null,
                ))->all());

                return ['summary' => SeatSummaryData::ofClaim($claim, $refs, $noFreePlaces, $rows)->toArray()];
            },
            order: 20,
        );

        // The ticket page (#292 §6.4): the way into the per-ticket picker,
        // offered only when this viewer may act on the ticket's claim —
        // `FindSeatClaimForAdmission` is the whole decision, and null means
        // the slot renders nothing. Reads nothing of Admission's tables: the
        // admission comes from this module's own read model.
        $slots->add(
            slot: 'admission.ticket.show',
            module: 'resources',
            component: 'resources/TicketSeatSlot',
            props: static function (array $context): array {
                $found = app(FindSeatClaimForAdmission::class)->handle($context['admissionId'], (string) auth()->id());

                if ($found === null) {
                    return ['seat' => null];
                }

                $placeId = ResourceBooking::query()
                    ->where('claim_id', $found['claim']->getKey())
                    ->where('admission_id', $found['admission']->id)
                    ->whereIn('state', BookingState::occupyingValues())
                    ->value('resource_id');

                /** @var \Konventix\Resources\Models\Resource|null $place */
                $place = $placeId === null ? null : Resource::query()->whereKey($placeId)->first();

                return ['seat' => (new TicketSeatActionData(
                    admissionId: $found['admission']->id,
                    placeLabel: $place === null ? null : ($place->ref ?? $place->label),
                    seatingMode: $found['claim']->seating_mode->value,
                    noFreePlaces: $placeId === null && ! app(HasFreePlaceForClaim::class)->handle($found['claim']),
                ))->toArray()];
            },
            order: 10,
        );
    }

    protected function registerListeners(): void
    {
        Event::listen(
            RegistrationConfirmed::class,
            FulfilClaimsOnRegistrationConfirmed::class,
        );

        Event::listen(
            RegistrationAmended::class,
            FulfilClaimsOnRegistrationAmended::class,
        );

        Event::listen(RegistrationCancelled::class, ReleaseBookingsOnRegistrationCancelled::class);

        // D-REG-15: a partial cancellation. The entitlement shrinks by the
        // places the removed units were worth and gives its surplus
        // bookings back — never the whole claim, which is what a
        // cancellation does. Ungated for the same reason the two releases
        // above are.
        Event::listen(RegistrationQuantityReduced::class, ReduceSeatClaimOnRegistrationQuantityReduced::class);
        Event::listen(RegistrationRejected::class, ReleaseBookingsOnRegistrationRejected::class);

        Event::listen(EventCreated::class, [ProjectEventWindow::class, 'handleCreated']);
        Event::listen(EventRescheduled::class, [ProjectEventWindow::class, 'handleRescheduled']);
        Event::listen(EventDetailsUpdated::class, [ProjectEventWindow::class, 'handleDetailsUpdated']);

        // Admissions, for the seat-per-ticket picker (#292 §6.1). Ungated:
        // a read model stays current whether or not a brand seats anybody.
        Event::listen(AdmissionIssued::class, [ProjectAdmission::class, 'handleIssued']);
        Event::listen(AdmissionHolderAssigned::class, [ProjectAdmission::class, 'handleHolderAssigned']);
        Event::listen(AdmissionHolderUnassigned::class, [ProjectAdmission::class, 'handleHolderUnassigned']);
        Event::listen(AdmissionRevoked::class, [ProjectAdmission::class, 'handleRevoked']);
        Event::listen(AdmissionOwnershipAnnounced::class, [ProjectAdmission::class, 'handleOwnershipAnnounced']);
        // Ungated like the other release listeners (#292 §6.5).
        Event::listen(AdmissionRevoked::class, ReleaseBookingOnAdmissionRevoked::class);

        // ADR-0059: Resources' own metrics, from its own events.
        Event::listen(
            [ResourceBookingHeld::class, ResourceBookingConfirmed::class, ResourceBookingReleased::class, ResourceBookingRefused::class],
            RecordBookingMetrics::class,
        );
        Event::listen([PlanSaved::class, PlanSaveRefused::class], RecordPlanMetrics::class);
    }

    /**
     * The hold sweep. Scheduled here rather than in a central kernel —
     * module wiring never goes into a central provider (ADR-0003).
     * Registered explicitly: modular discovers nothing under a module's
     * `src/Console`, and a sweep that silently failed to register would
     * look like "no holds ever lapse" rather than like a bug.
     */
    protected function registerSchedule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireResourceHoldsCommand::class, PlanImportCommand::class, PlanExportCommand::class, AssignPlaceCommand::class, ProbeSeatRace::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // `withoutOverlapping()` (Commerce's sweeps use the same guard):
            // the chunked read has no row lock, so a run that falls behind a
            // backlog — or a manual run colliding with the scheduled one —
            // must not stack a second pass on top of the first's, which
            // would let both select the same lapsed row and both emit
            // `ResourceBookingReleased` for it.
            $schedule->command(ExpireResourceHoldsCommand::class)->everyMinute()->withoutOverlapping();
        });
    }

    /**
     * Claims, plan versions and operator reservations point at people; the
     * handler nulls those pointers and exports what they said (ADR-0004:
     * Compliance orchestrates, this module touches its own rows).
     */
    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new ResourcesGdprHandler);
    }
}
