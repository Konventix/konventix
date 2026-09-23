<?php

declare(strict_types=1);

namespace Konventix\Resources\Plans;

use Konventix\Resources\Data\PlanDiffSummaryData;
use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Data\PlanElementData;
// Aliased: an unqualified "Resource" in a docblock generic collides with
// PHP's `resource` pseudo-type and pint's docblock fixer silently lowercases
// it, retyping the value to the wrong thing (the same root cause as the
// `Builder<Resource>` → `Builder<static>` trap elsewhere in this module).
use Konventix\Resources\Models\Resource as BookableResource;

/**
 * What `PlanDiff::compute()` found. `rows` is the projection the document
 * asks for (every place, keyed by id); `current` is what the table holds
 * for this plan, soft-deleted rows included.
 *
 * @phpstan-type ProjectionRow array{id: string, resource_type_id: string, label: string, ref: ?string, zone_id: ?string, group_id: ?string, accessible: bool, blocked: bool, neighbors: list<string>, origin_id: ?string}
 */
final readonly class PlanDiffResult
{
    /**
     * @param  list<string>  $added
     * @param  array<string, list<string>>  $changed  place id => changed projection columns
     * @param  list<string>  $removed
     * @param  list<string>  $foreign
     * @param  array<string, ProjectionRow>  $rows
     * @param  array<string, BookableResource>  $current
     */
    public function __construct(
        public array $added,
        public array $changed,
        public array $removed,
        public array $foreign,
        public array $rows,
        public array $current,
    ) {}

    /**
     * Every place whose projection row this save creates, changes or
     * removes — the `changedPlaceIds` of `PlanSaved`. Sorted for stable
     * payloads.
     *
     * @return list<string>
     */
    public function changedPlaceIds(): array
    {
        $ids = array_values(array_unique([...$this->added, ...array_keys($this->changed), ...$this->removed]));
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * Existing rows this save touches — the ones `SavePlan` locks.
     *
     * @return list<string>
     */
    public function affectedIds(): array
    {
        return array_values(array_unique([...array_keys($this->changed), ...$this->removed]));
    }

    public function fieldChanged(string $placeId, string $column): bool
    {
        return in_array($column, $this->changed[$placeId] ?? [], true);
    }

    public function summary(): PlanDiffSummaryData
    {
        return new PlanDiffSummaryData(
            added: count($this->added),
            changed: count($this->changed),
            removed: count($this->removed),
        );
    }

    /**
     * Validation errors for the foreign ids, keyed the way the editor
     * highlights them (`document.elements.<index>.id`).
     *
     * @return array<string, list<string>>
     */
    public function foreignErrors(PlanDocumentData $document): array
    {
        $errors = [];
        $index = 0;

        /** @var PlanElementData $element */
        foreach ($document->elements as $element) {
            if (in_array($element->id, $this->foreign, true)) {
                $errors["document.elements.{$index}.id"] = [__('This place id already exists under another plan or brand.')];
            }

            $index++;
        }

        return $errors;
    }
}
