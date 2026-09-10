<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Resources\Data\PlanConflictData;
use Konventix\Resources\Data\PlanConflictResolutionData;
use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Data\PlanSaveData;
use Konventix\Resources\Data\PlanSaveResultData;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\PlanVersionSource;
use Konventix\Resources\Events\PlanSaved;
use Konventix\Resources\Events\PlanSaveRefused;
use Konventix\Resources\Exceptions\PlanConflictException;
use Konventix\Resources\Exceptions\PlanStaleException;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\PlanVersion;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceType;
use Konventix\Resources\Plans\PlanConflicts;
use Konventix\Resources\Plans\PlanDiff;
use Konventix\Resources\Plans\PlanDiffResult;
use Konventix\Resources\Plans\PlanInvariants;
use Konventix\Resources\Plans\PlanWarnings;

/**
 * Saves a plan document and projects it into `resources` (design §6.1), in
 * this order and no other:
 *
 * 0. version check — `PlanStaleException` (409);
 * 1. Data validation — already done by the time a `PlanSaveData` exists;
 * 2. structural invariants — `PlanInvariants::check()` (422);
 * 3. read-only diff against the projection, foreign ids refused (422);
 * 4. conflicts against occupying bookings without a resolution —
 *    `PlanConflictException` (422), nothing written;
 * 5. warnings, non-blocking;
 * 6. one transaction: plan row `FOR UPDATE`, version re-checked, affected
 *    rows and their bookings locked, conflicts re-detected under the lock,
 *    resolutions applied through `ReleaseResourceBooking` (claims back to
 *    `unplaced`), projection upserted in batches with `brand_id` from the
 *    plan, removed places soft-deleted, version row written, `version++`,
 *    versions beyond fifty pruned;
 * 7. after the commit: `PlanSaved` and the deferred booking events.
 *
 * Steps 3 and 4 run once before any lock so a refused save costs nothing,
 * and again inside the transaction because a place can be booked between
 * the two. The projection carries no booking state (§6.2): availability is
 * always a query over `resource_bookings`.
 *
 * Runs on the operator host without a brand: every query escapes the brand
 * scope and every row is stamped from the plan.
 */
final class SavePlan
{
    public const int VERSIONS_KEPT = 50;

    private const int UPSERT_CHUNK = 250;

    public function __construct(
        private readonly PlanDiff $diff,
        private readonly PlanConflicts $conflicts,
        private readonly ReleaseResourceBooking $release,
    ) {}

    /**
     * Design §6.1. The pipeline itself is `save()`; this wrapper only turns
     * the three refusals into `PlanSaveRefused` so the plan-save counter
     * moves (ADR-0059), then rethrows unchanged — the HTTP mapping (409,
     * 422) stays with the controller. Dispatched only once `save()` has
     * fully returned control here: every refusal `save()` can throw, the
     * ones re-checked under the transaction's lock included, already
     * unwound through `DB::transaction()`'s own rollback before reaching
     * this catch, so the metrics listener's own query never runs against a
     * transaction Postgres still has aborted.
     */
    public function handle(Plan $plan, PlanSaveData $data, ?string $actorUserId, PlanVersionSource $source): PlanSaveResultData
    {
        try {
            return $this->save($plan, $data, $actorUserId, $source);
        } catch (PlanStaleException $e) {
            $this->refused($plan, 'stale');

            throw $e;
        } catch (PlanConflictException $e) {
            $this->refused($plan, 'conflict');

            throw $e;
        } catch (ValidationException $e) {
            $this->refused($plan, 'invalid');

            throw $e;
        }
    }

    private function refused(Plan $plan, string $outcome): void
    {
        event(new PlanSaveRefused(
            planId: $plan->id,
            eventId: $plan->event_id,
            brandId: $plan->brand_id,
            outcome: $outcome,
        ));
    }

    /**
     * Design §6.1 steps 0–6, wrapped by `handle()` above so every refusal
     * is reported. `ValidatePlan` (the dry run) does not call this: a
     * validation request is not a save and must not count as a refused one.
     */
    private function save(Plan $plan, PlanSaveData $data, ?string $actorUserId, PlanVersionSource $source): PlanSaveResultData
    {
        $this->guardVersion($plan, $data->expectedVersion);
        $this->guardInvariants($plan, $data->document);

        $preview = $this->diff->compute($plan, $data->document);
        $this->refuseForeignIds($preview, $data->document);
        $this->guardConflicts($this->conflicts->detect($plan, $preview), $data->resolutions);

        $warnings = PlanWarnings::collect($data->document);

        /** @var array{version: int, changed: list<string>} $outcome */
        $outcome = DB::transaction(function () use ($plan, $data, $actorUserId, $source): array {
            Plan::withoutBrandScope()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $plan->refresh();
            $this->guardVersion($plan, $data->expectedVersion);

            $diff = $this->diff->compute($plan, $data->document);
            $this->refuseForeignIds($diff, $data->document);
            $this->lockAffectedRows($diff);

            $conflicts = $this->conflicts->detect($plan, $diff, lock: true);
            $this->guardConflicts($conflicts, $data->resolutions);
            $this->applyResolutions($conflicts);

            $this->project($plan, $diff);

            $version = $plan->version + 1;
            $this->writeVersion($plan, $data->document, $version, $source, $actorUserId);
            $plan->forceFill(['document' => $data->document, 'version' => $version])->save();
            $this->pruneVersions($plan, $version);

            $changed = $diff->changedPlaceIds();

            DB::afterCommit(static function () use ($plan, $version, $changed): void {
                event(new PlanSaved(
                    planId: $plan->id,
                    eventId: $plan->event_id,
                    brandId: $plan->brand_id,
                    version: $version,
                    changedPlaceIds: $changed,
                ));
            });

            return ['version' => $version, 'changed' => $changed];
        });

        return new PlanSaveResultData(
            version: $outcome['version'],
            changedPlaceIds: $outcome['changed'],
            warnings: $warnings,
            savedAt: now()->toIso8601String(),
        );
    }

    private function guardVersion(Plan $plan, int $expectedVersion): void
    {
        if ($expectedVersion !== $plan->version) {
            throw new PlanStaleException($plan->version);
        }
    }

    /**
     * Design §6.1 step 2. `PlanInvariants::check()` returns violations and
     * throws nothing (S1, Task 11), so the 422 is made here: every entry
     * already carries the field path (`document.elements.<index>.<field>`),
     * and several violations may share one path, which is why the list is
     * folded into `path => list<message>` before it is thrown.
     *
     * The category ids are the event's own, read without the brand scope
     * because a save runs on the operator host with no brand context.
     */
    private function guardInvariants(Plan $plan, PlanDocumentData $document): void
    {
        $violations = PlanInvariants::check(
            $document,
            ResourceType::withoutBrandScope()
                ->where('brand_id', $plan->brand_id)
                ->where('event_id', $plan->event_id)
                ->pluck('mode', 'id')
                ->all(),
        );

        if ($violations === []) {
            return;
        }

        throw ValidationException::withMessages(
            array_reduce(
                $violations,
                static function (array $carry, array $violation): array {
                    $carry[$violation['path']][] = $violation['message'];

                    return $carry;
                },
                [],
            ),
        );
    }

    private function refuseForeignIds(PlanDiffResult $diff, PlanDocumentData $document): void
    {
        if ($diff->foreign !== []) {
            throw ValidationException::withMessages($diff->foreignErrors($document));
        }
    }

    /**
     * @param  list<PlanConflictData>  $conflicts
     * @param  list<PlanConflictResolutionData>  $resolutions
     */
    private function guardConflicts(array $conflicts, array $resolutions): void
    {
        if ($conflicts === []) {
            return;
        }

        $resolved = [];

        foreach ($resolutions as $resolution) {
            if ($resolution->resolution === PlanConflictResolutionData::RELEASE) {
                $resolved[$resolution->placeId] = true;
            }
        }

        foreach ($conflicts as $conflict) {
            if (! isset($resolved[$conflict->placeId])) {
                throw new PlanConflictException($conflicts);
            }
        }
    }

    private function lockAffectedRows(PlanDiffResult $diff): void
    {
        $ids = $diff->affectedIds();

        if ($ids === []) {
            return;
        }

        Resource::withoutBrandScope()->withTrashed()->whereIn('id', $ids)->lockForUpdate()->get();
    }

    /**
     * Every conflict left here carries a `release` resolution (the guard
     * threw otherwise): the booking goes back with reason `plan_conflict`
     * and its claim to `unplaced`, so "Meine Registrierung" asks for a seat
     * again (design §6.3). No mail (operator decision 2026-09-10).
     *
     * @param  list<PlanConflictData>  $conflicts
     */
    private function applyResolutions(array $conflicts): void
    {
        foreach ($conflicts as $conflict) {
            foreach ($conflict->bookings as $entry) {
                $booking = ResourceBooking::withoutBrandScope()->whereKey($entry->bookingId)->firstOrFail();
                $this->release->handle($booking, BookingState::Released, 'plan_conflict');

                if ($entry->claimId !== null) {
                    ResourceClaim::withoutBrandScope()
                        ->whereKey($entry->claimId)
                        ->update(['state' => ClaimState::Unplaced->value]);
                }
            }
        }
    }

    /**
     * Three statements, in this order and no other:
     *
     * 1. soft-delete what the document dropped — `deleted_at = now()`, so
     *    the booking history of a removed place stays attached (§4.2) and
     *    the row leaves the partial unique, which only covers
     *    `deleted_at IS NULL`;
     * 2. null the `ref` of every live place whose ref changed;
     * 3. upsert every place the document holds — all of them, not only the
     *    changed ones, because neighbours and `is_published` are derived and
     *    a sibling's move changes a row the diff calls unchanged. A
     *    returning id gets `deleted_at` back to null.
     *
     * Step 2 exists because `resources_plan_ref_unique` is a *partial*
     * unique index, which Postgres cannot make DEFERRABLE: it is checked row
     * by row inside the upsert. Two places swapping refs (`A-01` ↔ `A-02`),
     * or a row taking the ref of a place removed in the same save, would hit
     * 23505 halfway through the statement and lose the whole save. Freeing
     * the old refs first — and flushing both statements before the upsert —
     * makes the order of the rows inside the upsert irrelevant.
     *
     * `brand_id` comes from the plan, never from context.
     */
    private function project(Plan $plan, PlanDiffResult $diff): void
    {
        if ($diff->removed !== []) {
            // SoftDeletes turns this into `deleted_at = now()`; the booking
            // history of a removed place stays attached (design §4.2).
            Resource::withoutBrandScope()->whereIn('id', $diff->removed)->delete();
        }

        $refChanged = array_keys(array_filter(
            $diff->changed,
            static fn (array $fields): bool => in_array('ref', $fields, true),
        ));

        if ($refChanged !== []) {
            Resource::withoutBrandScope()->whereIn('id', $refChanged)->update(['ref' => null]);
        }

        $now = now();
        $published = $plan->published_at !== null;
        $rows = [];

        foreach ($diff->rows as $row) {
            $rows[] = [
                'id' => $row['id'],
                'brand_id' => $plan->brand_id,
                'plan_id' => $plan->id,
                'resource_type_id' => $row['resource_type_id'],
                'venue_id' => $plan->venue_id,
                'label' => $row['label'],
                'ref' => $row['ref'],
                'zone_id' => $row['zone_id'],
                'group_id' => $row['group_id'],
                'accessible' => $row['accessible'],
                'blocked' => $row['blocked'],
                'neighbors' => json_encode($row['neighbors'], JSON_THROW_ON_ERROR),
                'origin_id' => $row['origin_id'],
                'capacity' => 1,
                'is_published' => $published,
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            Resource::withoutBrandScope()->upsert($chunk, ['id'], [
                'resource_type_id', 'venue_id', 'label', 'ref', 'zone_id', 'group_id',
                'accessible', 'blocked', 'neighbors', 'origin_id', 'is_published', 'deleted_at', 'updated_at',
            ]);
        }
    }

    private function writeVersion(Plan $plan, PlanDocumentData $document, int $version, PlanVersionSource $source, ?string $actorUserId): void
    {
        $row = new PlanVersion;
        $row->forceFill([
            'brand_id' => $plan->brand_id,
            'plan_id' => $plan->id,
            'version' => $version,
            'document' => $document,
            'source' => $source->value,
            'created_by' => $actorUserId,
            'created_at' => now(),
        ])->save();
    }

    private function pruneVersions(Plan $plan, int $version): void
    {
        PlanVersion::withoutBrandScope()
            ->where('plan_id', $plan->id)
            ->where('version', '<=', $version - self::VERSIONS_KEPT)
            ->delete();
    }
}
