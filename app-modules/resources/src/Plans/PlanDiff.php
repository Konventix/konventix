<?php

declare(strict_types=1);

namespace Konventix\Resources\Plans;

use Illuminate\Database\Eloquent\Builder;
use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Enums\ElementKind;
use Konventix\Resources\Models\Plan;
// Aliased: an unqualified "Resource" in a docblock generic collides with
// PHP's `resource` pseudo-type and pint's docblock fixer silently lowercases
// it, retyping the value to the wrong thing (the same root cause as the
// `Builder<Resource>` → `Builder<static>` trap elsewhere in this module).
use Konventix\Resources\Models\Resource as BookableResource;

/**
 * The read-only diff of a document against the projection (design §6.1
 * step 3). Only projected columns count as a change; geometry lives in the
 * document alone (§6.2) and moving a place is never a conflict (§6.3).
 *
 * Foreign ids — a `resources` row with a place's id under another plan, a
 * plan-less resource, or another brand — are looked up `withoutBrandScope`
 * on purpose: the console runs without a brand, and a brand-scoped lookup
 * would answer "unknown" for exactly the ids that must be refused.
 *
 * @phpstan-import-type ProjectionRow from PlanDiffResult
 */
final class PlanDiff
{
    /**
     * The projection columns compared for "changed", in a fixed order so
     * the changed-field lists are stable.
     */
    public const array COMPARED = ['label', 'ref', 'resource_type_id', 'blocked', 'accessible', 'zone_id', 'group_id'];

    public function compute(Plan $plan, PlanDocumentData $document): PlanDiffResult
    {
        $rows = self::projectionRows($document);

        /** @var array<string, BookableResource> $current */
        $current = BookableResource::withoutBrandScope()
            ->withTrashed()
            ->where('plan_id', $plan->id)
            ->get()
            ->keyBy('id')
            ->all();

        $added = [];
        $changed = [];
        $removed = [];

        foreach ($rows as $id => $row) {
            $existing = $current[$id] ?? null;

            if ($existing === null || $existing->deleted_at !== null) {
                $added[] = $id;

                continue;
            }

            $fields = [];

            foreach (self::COMPARED as $column) {
                if ($existing->getAttribute($column) !== $row[$column]) {
                    $fields[] = $column;
                }
            }

            if ($fields !== []) {
                $changed[$id] = $fields;
            }
        }

        foreach ($current as $id => $existing) {
            if (! isset($rows[$id]) && $existing->deleted_at === null) {
                $removed[] = $id;
            }
        }

        return new PlanDiffResult(
            added: $added,
            changed: $changed,
            removed: $removed,
            foreign: $this->foreignIds($plan, array_keys($rows)),
            rows: $rows,
            current: $current,
        );
    }

    /**
     * The projection row every place asks for: derived zone and group from
     * the parent chain, neighbours from world geometry, label falling back
     * to ref and id because `resources.label` is not nullable.
     *
     * @return array<string, ProjectionRow>
     */
    public static function projectionRows(PlanDocumentData $document): array
    {
        $geometry = new PlanGeometry($document);
        $neighbors = PlanNeighbors::compute($document);
        $rows = [];

        foreach ($geometry->ofKind(ElementKind::Place) as $place) {
            $rows[$place->id] = [
                'id' => $place->id,
                'resource_type_id' => (string) $place->resourceTypeId,
                'label' => $place->label ?? $place->ref ?? $place->id,
                'ref' => $place->ref,
                'zone_id' => $geometry->ancestorOfKind($place->id, ElementKind::Zone)?->id,
                'group_id' => $geometry->ancestorOfKind($place->id, ElementKind::Group)?->id,
                'accessible' => $place->accessible,
                'blocked' => $place->blocked,
                'neighbors' => $neighbors[$place->id] ?? [],
                'origin_id' => $place->originId,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function foreignIds(Plan $plan, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<string> $foreign */
        $foreign = BookableResource::withoutBrandScope()
            ->withTrashed()
            ->whereIn('id', $ids)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('plan_id')
                ->orWhere('plan_id', '!=', $plan->id)
                ->orWhere('brand_id', '!=', $plan->brand_id))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return $foreign;
    }
}
