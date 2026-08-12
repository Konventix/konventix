<?php

declare(strict_types=1);

namespace Konventix\Registration\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Konventix\Registration\Enums\Permission;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * Operator console: per-event registration numbers (ADR-0029), consumed by
 * the `registration/EventStatsCard` component the Event admin dashboard
 * renders through its `event.admin.dashboard` UI slot. Event never queries
 * this module's models directly — it only knows the slot's component name
 * and the event ULID; this endpoint is how the card gets its own data.
 *
 * `Registration` and `RegistrationType` are brand-owned (ADR-0010); the admin
 * host hardens `BrandScope` to nothing (ADR-0029), so every query here uses
 * the explicit `withoutBrandScope()` escape hatch deliberately — the event
 * ULID in the route already implies which brand these rows belong to.
 *
 * `Quota` is looked up through the (also brand-owned) `registration_type_quota`
 * pivot with the plain query builder (`DB::table`) rather than an Eloquent
 * relation: eager-loading a relation re-triggers the related model's own
 * global scope on a fresh query, so `RegistrationType::withoutBrandScope()
 * ->with('quotas')` would still come back empty here — `withoutBrandScope()`
 * only strips the scope from the builder it is called on, not from queries
 * relations build internally.
 */
final class RegistrationStatsController
{
    /**
     * Statuses that still hold a quota slot — `SubmitRegistration` consumes
     * one unit per quota at submission time, `RejectRegistration` and
     * `CancelRegistration` release it (`ReleasesQuotas`). "Sold" in the
     * per-type table means "currently counts against quota".
     *
     * @var list<RegistrationStatus>
     */
    private const ACTIVE_STATUSES = [
        RegistrationStatus::Submitted,
        RegistrationStatus::Approved,
        RegistrationStatus::Confirmed,
    ];

    public function __invoke(string $event): JsonResponse
    {
        Gate::authorize(Permission::ViewRegistrations->value);

        $registrations = Registration::withoutBrandScope()
            ->where('event_id', $event)
            ->get(['registration_type_id', 'status']);

        $counts = collect(RegistrationStatus::cases())
            ->mapWithKeys(fn (RegistrationStatus $status): array => [
                $status->value => $registrations->where('status', $status)->count(),
            ]);

        $types = RegistrationType::withoutBrandScope()
            ->where('event_id', $event)
            ->orderBy('name')
            ->get(['id', 'name']);

        /** @var Collection<int, object{registration_type_id: string, quota_id: string}> $quotaLinks */
        $quotaLinks = DB::table('registration_type_quota')
            ->whereIn('registration_type_id', $types->pluck('id'))
            ->get(['registration_type_id', 'quota_id']);

        $quotas = Quota::withoutBrandScope()
            ->whereIn('id', $quotaLinks->pluck('quota_id')->unique())
            ->get(['id', 'capacity', 'consumed'])
            ->keyBy('id');

        return response()->json([
            'counts' => $counts,
            'types' => $types->map(fn (RegistrationType $type): array => $this->typeRow(
                $type,
                $registrations,
                $quotaLinks,
                $quotas,
            ))->values(),
        ]);
    }

    /**
     * @param  Collection<int, Registration>  $registrations
     * @param  Collection<int, object{registration_type_id: string, quota_id: string}>  $quotaLinks
     * @param  Collection<string, Quota>  $quotas
     * @return array{id: string, name: string, sold: int, capacity: int|null, remaining: int|null}
     */
    private function typeRow(
        RegistrationType $type,
        Collection $registrations,
        Collection $quotaLinks,
        Collection $quotas,
    ): array {
        $sold = $registrations
            ->where('registration_type_id', $type->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->count();

        $typeQuotas = $quotaLinks
            ->where('registration_type_id', $type->id)
            ->map(fn (object $link): ?Quota => $quotas->get($link->quota_id))
            ->filter();

        // A type referencing no quota at all sells without a capacity
        // ceiling — `null` means "unlimited", not zero.
        $capacity = $typeQuotas->isEmpty() ? null : (int) $typeQuotas->sum('capacity');
        $remaining = $typeQuotas->isEmpty()
            ? null
            : (int) $typeQuotas->sum(fn (Quota $quota): int => $quota->remaining());

        return [
            'id' => $type->id,
            'name' => $type->name,
            'sold' => $sold,
            'capacity' => $capacity,
            'remaining' => $remaining,
        ];
    }
}
