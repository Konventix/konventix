<?php

declare(strict_types=1);

namespace Konventix\Resources\Plans;

use InvalidArgumentException;

/**
 * An axis-aligned bounding box in world millimetres. Comparisons carry a
 * 1 mm slack: rotation leaves floating-point dust on the corners, and the
 * plan has no feature smaller than a millimetre to lose.
 */
final readonly class Box
{
    private const float EPSILON = 1.0;

    public function __construct(
        public float $minX,
        public float $minY,
        public float $maxX,
        public float $maxY,
    ) {}

    /**
     * @param  list<array{0: float, 1: float}>  $points
     */
    public static function ofPoints(array $points): self
    {
        // Deliberately unreachable through PlanGeometry: every shape kind's
        // localCorners() returns at least one point (a polygon falls back to
        // [[0, 0]] when points is null, and PlanInvariants refuses a polygon
        // with fewer than three points before any geometry runs). Kept as a
        // defensive guard against a future direct caller of Box::ofPoints().
        if ($points === []) {
            throw new InvalidArgumentException('A box needs at least one point.');
        }

        $xs = array_column($points, 0);
        $ys = array_column($points, 1);

        return new self(min($xs), min($ys), max($xs), max($ys));
    }

    /**
     * Touching counts; `$tolerance` widens both boxes by that many mm first.
     */
    public function intersects(self $other, float $tolerance = 0.0): bool
    {
        return $this->minX - $tolerance <= $other->maxX + self::EPSILON
            && $other->minX - $tolerance <= $this->maxX + self::EPSILON
            && $this->minY - $tolerance <= $other->maxY + self::EPSILON
            && $other->minY - $tolerance <= $this->maxY + self::EPSILON;
    }

    /**
     * More than a millimetre of shared area in both axes — touching is not
     * overlapping.
     */
    public function overlaps(self $other): bool
    {
        $depthX = min($this->maxX, $other->maxX) - max($this->minX, $other->minX);
        $depthY = min($this->maxY, $other->maxY) - max($this->minY, $other->minY);

        return $depthX > self::EPSILON && $depthY > self::EPSILON;
    }

    public function contains(self $other): bool
    {
        return $other->minX >= $this->minX - self::EPSILON
            && $other->minY >= $this->minY - self::EPSILON
            && $other->maxX <= $this->maxX + self::EPSILON
            && $other->maxY <= $this->maxY + self::EPSILON;
    }

    public function union(self $other): self
    {
        return new self(
            min($this->minX, $other->minX),
            min($this->minY, $other->minY),
            max($this->maxX, $other->maxX),
            max($this->maxY, $other->maxY),
        );
    }
}
