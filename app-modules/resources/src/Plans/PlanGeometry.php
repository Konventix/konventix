<?php

declare(strict_types=1);

namespace Konventix\Resources\Plans;

use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Data\PlanElementData;
use Konventix\Resources\Data\ShapeData;
use Konventix\Resources\Enums\ElementKind;
use Konventix\Resources\Enums\ShapeKind;

/**
 * Reads one document once and answers geometry questions about it: parent
 * chains, world transforms and world bounding boxes. Everything is
 * memoised per instance; build a new one per document.
 *
 * Guards against a parent cycle with a visited set rather than trusting
 * the caller: warnings run after `PlanInvariants`, but this class must not
 * loop forever when somebody calls it first.
 */
final class PlanGeometry
{
    /** @var array<string, PlanElementData> */
    private array $byId = [];

    /** @var array<string, WorldTransform> */
    private array $transforms = [];

    /** @var array<string, Box> */
    private array $boxes = [];

    public function __construct(PlanDocumentData $document)
    {
        /** @var PlanElementData $element */
        foreach ($document->elements as $element) {
            $this->byId[$element->id] = $element;
        }
    }

    public function element(string $id): ?PlanElementData
    {
        return $this->byId[$id] ?? null;
    }

    /**
     * @return list<PlanElementData>
     */
    public function ofKind(ElementKind $kind): array
    {
        return array_values(array_filter(
            $this->byId,
            fn (PlanElementData $element): bool => $element->kind === $kind,
        ));
    }

    /**
     * The nearest ancestor of the given kind, walking `parentId` upwards.
     */
    public function ancestorOfKind(string $id, ElementKind $kind): ?PlanElementData
    {
        $visited = [$id => true];
        $current = $this->byId[$id] ?? null;

        while ($current !== null && $current->parentId !== null) {
            if (isset($visited[$current->parentId])) {
                return null;
            }

            $visited[$current->parentId] = true;
            $current = $this->byId[$current->parentId] ?? null;

            if ($current !== null && $current->kind === $kind) {
                return $current;
            }
        }

        return null;
    }

    public function worldTransform(string $id): WorldTransform
    {
        if (isset($this->transforms[$id])) {
            return $this->transforms[$id];
        }

        $element = $this->byId[$id] ?? null;

        if ($element === null) {
            return WorldTransform::identity();
        }

        $parent = $element->parentId !== null && $element->parentId !== $id && isset($this->byId[$element->parentId])
            ? $this->worldTransform($element->parentId)
            : WorldTransform::identity();

        return $this->transforms[$id] = $parent->child($element->x, $element->y, $element->rotation);
    }

    public function worldBox(string $id): Box
    {
        if (isset($this->boxes[$id])) {
            return $this->boxes[$id];
        }

        $element = $this->byId[$id] ?? null;
        $transform = $this->worldTransform($id);
        $corners = $element === null ? [[0.0, 0.0]] : $this->localCorners($element->shape);

        return $this->boxes[$id] = Box::ofPoints(array_map(
            fn (array $corner): array => $transform->apply($corner[0], $corner[1]),
            $corners,
        ));
    }

    /**
     * The union of every element's world box, or null for an empty document.
     */
    public function bounds(): ?Box
    {
        $bounds = null;

        foreach (array_keys($this->byId) as $id) {
            $box = $this->worldBox($id);
            $bounds = $bounds === null ? $box : $bounds->union($box);
        }

        return $bounds;
    }

    /**
     * Shape corners in the element's own frame (design §5): rect and polygon
     * hang from the top-left corner, ellipse and point sit on their centre.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function localCorners(ShapeData $shape): array
    {
        return match ($shape->type) {
            ShapeKind::Rect => [
                [0.0, 0.0],
                [(float) ($shape->w ?? 0), 0.0],
                [(float) ($shape->w ?? 0), (float) ($shape->h ?? 0)],
                [0.0, (float) ($shape->h ?? 0)],
            ],
            ShapeKind::Ellipse => (function () use ($shape): array {
                $rx = (float) ($shape->rx ?? $shape->radius ?? 0);
                $ry = (float) ($shape->ry ?? $shape->radius ?? 0);

                return [[-$rx, -$ry], [$rx, -$ry], [$rx, $ry], [-$rx, $ry]];
            })(),
            ShapeKind::Polygon => array_map(
                fn (array $point): array => [(float) $point[0], (float) $point[1]],
                $shape->points ?? [[0, 0]],
            ),
            ShapeKind::Point => [[0.0, 0.0]],
        };
    }
}
