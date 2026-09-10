<?php

declare(strict_types=1);

namespace Konventix\Resources\Gdpr;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\PlanVersion;
use Konventix\Resources\Models\Resource as BookableResource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;

/**
 * Resources' contribution to GDPR export and deletion (design §9.5).
 *
 * Three places point at a person: `resource_claims.registrant_user_id`
 * (who may pick for a registration), `resource_plans.created_by` and
 * `resource_plan_versions.created_by` (who drew the plan), and
 * `resource_bookings.holder_id` where `holder_type` is `operator` (an
 * orga reservation made by that user). Everything else references a
 * registration, which Registration tombstones itself.
 *
 * Export is what those rows say about the person: the claims, the seat
 * bookings they fulfil (plan name, ref, label, state, interval), their
 * operator reservations, and the versions they authored — never the plan
 * document itself, which is the operator's work, not the subject's.
 *
 * Deletion nulls the pointers and keeps the rows in both modes: a booking
 * is the event's occupancy history and a plan version its editing history.
 * The subject is a person, not a person-at-a-brand (ADR-0050 §2): every
 * query drops the brand scope. An email-only subject cannot be answered —
 * nothing here keys on an address. Both methods are idempotent.
 */
final class ResourcesGdprHandler implements GdprHandler
{
    public function module(): string
    {
        return 'resources';
    }

    public function exportFor(SubjectRef $subject): array
    {
        if (! $subject->isAccountBacked()) {
            return [];
        }

        $subjectId = (string) $subject->userId;

        $claims = ResourceClaim::withoutBrandScope()
            ->where('registrant_user_id', $subjectId)
            ->orderBy('created_at')
            ->get();

        $bookings = ResourceBooking::withoutBrandScope()
            ->where(function (Builder $query) use ($subjectId, $claims): void {
                $query->whereIn('claim_id', $claims->modelKeys())
                    ->orWhere(fn (Builder $reservations): Builder => $reservations
                        ->where('holder_type', ResourceBooking::HOLDER_OPERATOR)
                        ->where('holder_id', $subjectId));
            })
            ->orderBy('created_at')
            ->get();

        $places = $this->placesOf($bookings);

        $versions = PlanVersion::withoutBrandScope()
            ->where('created_by', $subjectId)
            ->orderBy('created_at')
            ->get();

        $planNames = $this->planNames(array_map('strval', [
            ...$places->pluck('plan_id')->filter()->values()->all(),
            ...$versions->pluck('plan_id')->values()->all(),
        ]));

        return [
            'claims' => $claims
                ->map(fn (ResourceClaim $claim): array => [
                    'id' => $claim->id,
                    'event_id' => $claim->event_id,
                    'registration_id' => $claim->registration_id,
                    'seating_mode' => $claim->seating_mode->value,
                    'quantity' => $claim->quantity,
                    'state' => $claim->state->value,
                    'created_at' => $claim->created_at?->toIso8601String(),
                ])
                ->all(),
            'bookings' => $bookings
                ->map(function (ResourceBooking $booking) use ($places, $planNames): array {
                    $place = $places->get($booking->resource_id);

                    return [
                        'id' => $booking->id,
                        'holder_type' => $booking->holder_type,
                        'registration_id' => $booking->registration_id,
                        'plan' => $place !== null ? ($planNames[$place->plan_id] ?? null) : null,
                        'ref' => $place?->ref,
                        'label' => $booking->label ?? $place?->label,
                        'state' => $booking->state->value,
                        'starts_at' => $booking->starts_at->toIso8601String(),
                        'ends_at' => $booking->ends_at->toIso8601String(),
                        'created_at' => $booking->created_at?->toIso8601String(),
                    ];
                })
                ->all(),
            'plan_versions' => $versions
                ->map(fn (PlanVersion $version): array => [
                    'plan_id' => $version->plan_id,
                    'plan' => $planNames[$version->plan_id] ?? null,
                    'version' => $version->version,
                    'source' => $version->source->value,
                    'created_at' => $version->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    public function delete(SubjectRef $subject, DeletionMode $mode): void
    {
        if (! $subject->isAccountBacked()) {
            return;
        }

        $subjectId = (string) $subject->userId;

        ResourceClaim::withoutBrandScope()
            ->where('registrant_user_id', $subjectId)
            ->update(['registrant_user_id' => null]);

        Plan::withoutBrandScope()
            ->where('created_by', $subjectId)
            ->update(['created_by' => null]);

        PlanVersion::withoutBrandScope()
            ->where('created_by', $subjectId)
            ->update(['created_by' => null]);

        ResourceBooking::withoutBrandScope()
            ->where('holder_type', ResourceBooking::HOLDER_OPERATOR)
            ->where('holder_id', $subjectId)
            ->update(['holder_id' => null]);
    }

    /**
     * The places behind the bookings, keyed by resource id. Soft-deleted
     * places are included: a booking on a place the operator later removed
     * from the plan is still part of the person's history.
     *
     * @param  Collection<int, ResourceBooking>  $bookings
     * @return Collection<string, BookableResource>
     */
    private function placesOf(Collection $bookings): Collection
    {
        return BookableResource::withoutBrandScope()
            ->withTrashed()
            ->whereIn('id', $bookings->pluck('resource_id')->unique()->all())
            ->get(['id', 'plan_id', 'ref', 'label'])
            ->keyBy('id');
    }

    /**
     * @param  list<string>  $planIds
     * @return array<string, string>
     */
    private function planNames(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        return Plan::withoutBrandScope()
            ->whereIn('id', array_values(array_unique($planIds)))
            ->pluck('name', 'id')
            ->all();
    }
}
