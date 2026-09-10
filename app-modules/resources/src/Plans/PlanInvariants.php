<?php

declare(strict_types=1);

namespace Konventix\Resources\Plans;

use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Data\PlanElementData;
use Konventix\Resources\Enums\ElementKind;
use Konventix\Resources\Enums\ShapeKind;

/**
 * The structural rules of a plan document (design §6.1 step 2): every
 * `parentId` exists, no element is its own ancestor, a kind sits only where
 * `ElementKind::allowsParent()` allows, ids and refs are unique, a place
 * has a category of this plan's event, a polygon has at least three points.
 *
 * Pure PHP over an already-built document: the caller hands in the
 * category ids it looked up, so this class needs no database and runs the
 * same way in `SavePlan`, `ValidatePlan` and a unit test. Every violation
 * is keyed the way the editor expects a 422 to be keyed,
 * `document.elements.<index>.<field>`, so `SavePlan` can pass the list
 * straight to `ValidationException::withMessages()`.
 */
final class PlanInvariants
{
    public const int POLYGON_MIN_POINTS = 3;

    /**
     * @param  list<string>  $resourceTypeIds  the categories of the plan's event and brand
     * @return list<array{path: string, message: string}>
     */
    public static function check(PlanDocumentData $document, array $resourceTypeIds): array
    {
        $violations = [];

        /** @var array<string, PlanElementData> $byId */
        $byId = [];

        foreach ($document->elements as $index => $element) {
            if (isset($byId[$element->id])) {
                $violations[] = self::violation($index, 'id', 'Element id is used more than once.');

                continue;
            }

            $byId[$element->id] = $element;
        }

        $allowedTypes = array_fill_keys($resourceTypeIds, true);
        $refs = [];

        foreach ($document->elements as $index => $element) {
            if ($element->parentId !== null) {
                $parent = $byId[$element->parentId] ?? null;

                if ($parent === null) {
                    $violations[] = self::violation($index, 'parentId', 'Parent element does not exist.');
                } elseif (! $element->kind->allowsParent($parent->kind)) {
                    $violations[] = self::violation($index, 'parentId', sprintf(
                        'A %s may not be placed inside a %s.',
                        $element->kind->value,
                        $parent->kind->value,
                    ));
                } elseif (self::isOwnAncestor($element, $byId)) {
                    $violations[] = self::violation($index, 'parentId', 'Element is its own ancestor.');
                }
            }

            if ($element->ref !== null) {
                if (isset($refs[$element->ref])) {
                    $violations[] = self::violation($index, 'ref', 'Ref is used more than once.');
                }

                $refs[$element->ref] = true;
            }

            if ($element->kind === ElementKind::Place) {
                if ($element->resourceTypeId === null) {
                    $violations[] = self::violation($index, 'resourceTypeId', 'A place needs a category.');
                } elseif (! isset($allowedTypes[$element->resourceTypeId])) {
                    $violations[] = self::violation($index, 'resourceTypeId', "Category does not belong to this plan's event.");
                }
            }

            if ($element->shape->type === ShapeKind::Polygon
                && count($element->shape->points ?? []) < self::POLYGON_MIN_POINTS) {
                $violations[] = self::violation($index, 'shape.points', 'A polygon needs at least three points.');
            }
        }

        return $violations;
    }

    /**
     * Walks the parent chain with a visited set, so a loop that does not
     * pass through `$element` terminates too — that loop's own members
     * report it.
     *
     * @param  array<string, PlanElementData>  $byId
     */
    private static function isOwnAncestor(PlanElementData $element, array $byId): bool
    {
        $seen = [];
        $cursor = $element->parentId;

        while ($cursor !== null) {
            if ($cursor === $element->id) {
                return true;
            }

            if (isset($seen[$cursor])) {
                return false;
            }

            $seen[$cursor] = true;
            $cursor = ($byId[$cursor] ?? null)?->parentId;
        }

        return false;
    }

    /**
     * @return array{path: string, message: string}
     */
    private static function violation(int $index, string $field, string $message): array
    {
        return ['path' => "document.elements.{$index}.{$field}", 'message' => $message];
    }
}
