<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Validation\ValidationException;
use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Data\PlanSaveData;
use Konventix\Resources\Data\PlanValidationData;
use Konventix\Resources\Exceptions\PlanStaleException;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\ResourceType;
use Konventix\Resources\Plans\PlanConflicts;
use Konventix\Resources\Plans\PlanDiff;
use Konventix\Resources\Plans\PlanInvariants;
use Konventix\Resources\Plans\PlanWarnings;

/**
 * The dry run of `SavePlan` (design §6.1): steps 0–5 with the same refusals
 * for a stale version, broken invariants and foreign ids, but conflicts are
 * *returned* rather than thrown — this is what the editor's conflict dialog
 * lists before it re-sends the save with resolutions. Writes nothing and
 * takes no lock.
 */
final class ValidatePlan
{
    public function __construct(
        private readonly PlanDiff $diff,
        private readonly PlanConflicts $conflicts,
    ) {}

    public function handle(Plan $plan, PlanSaveData $data): PlanValidationData
    {
        if ($data->expectedVersion !== $plan->version) {
            throw new PlanStaleException($plan->version);
        }

        $this->guardInvariants($plan, $data->document);

        $diff = $this->diff->compute($plan, $data->document);

        if ($diff->foreign !== []) {
            throw ValidationException::withMessages($diff->foreignErrors($data->document));
        }

        return new PlanValidationData(
            conflicts: $this->conflicts->detect($plan, $diff),
            warnings: PlanWarnings::collect($data->document),
            diff: $diff->summary(),
        );
    }

    /**
     * The same refusal `SavePlan::guardInvariants()` makes (design §6.1
     * step 2): `PlanInvariants::check()` returns a violation list, and the
     * 422 keyed `document.elements.<index>.<field>` is made from it here.
     * The dry run must refuse exactly what the save refuses, or the editor
     * would show a green validation and then a red save.
     */
    private function guardInvariants(Plan $plan, PlanDocumentData $document): void
    {
        $violations = PlanInvariants::check(
            $document,
            array_values(ResourceType::withoutBrandScope()
                ->where('brand_id', $plan->brand_id)
                ->where('event_id', $plan->event_id)
                ->pluck('id')
                ->all()),
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
}
