<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Data\PlanSaveData;
use Konventix\Resources\Data\PlanSaveResultData;
use Konventix\Resources\Enums\PlanVersionSource;
use Konventix\Resources\Models\Plan;

/**
 * Replaces a plan's document with an uploaded one (design §6.4): the array
 * is validated as `PlanDocumentData` (a bad upload is an ordinary 422), then
 * saved through `SavePlan` with source `import` against the plan's current
 * version — so every rule of a save applies: unknown ids are new places,
 * ids under another plan or brand are refused, an occupied place the upload
 * drops is a conflict the caller has to resolve like any other.
 *
 * `$categoryMap` rewrites `resourceTypeId`s before validation, for documents
 * written against another event's categories or the committed example's
 * placeholder (`PlanImportCommand::PLACEHOLDER_CATEGORY`).
 */
final class ImportPlan
{
    public function __construct(
        private readonly SavePlan $save,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, string>  $categoryMap  old resource type id => new resource type id
     */
    public function handle(Plan $plan, array $document, ?string $actorUserId, array $categoryMap = []): PlanSaveResultData
    {
        if ($categoryMap !== [] && isset($document['elements']) && is_array($document['elements'])) {
            foreach ($document['elements'] as $index => $element) {
                if (is_array($element) && isset($element['resourceTypeId']) && isset($categoryMap[$element['resourceTypeId']])) {
                    $document['elements'][$index]['resourceTypeId'] = $categoryMap[$element['resourceTypeId']];
                }
            }
        }

        $data = new PlanSaveData(
            expectedVersion: $plan->version,
            document: PlanDocumentData::validateAndCreate($document),
            resolutions: [],
        );

        return $this->save->handle($plan, $data, $actorUserId, PlanVersionSource::Import);
    }
}
