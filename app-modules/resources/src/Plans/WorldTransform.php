<?php

declare(strict_types=1);

namespace Konventix\Resources\Plans;

/**
 * An affine transform in the SVG convention `matrix(a b c d e f)`:
 * `x' = a·x + c·y + e`, `y' = b·x + d·y + f`. Plan coordinates are
 * millimetres, origin top-left, Y down, rotation in degrees clockwise about
 * the element origin (design §5), which is exactly SVG's `rotate()` in a
 * Y-down space — so the frontend's `transform.ts` and this class agree by
 * construction.
 */
final readonly class WorldTransform
{
    public function __construct(
        public float $a,
        public float $b,
        public float $c,
        public float $d,
        public float $e,
        public float $f,
    ) {}

    public static function identity(): self
    {
        return new self(1.0, 0.0, 0.0, 1.0, 0.0, 0.0);
    }

    /**
     * This transform followed by a child's `translate(x, y) rotate(deg)` —
     * the child's local frame expressed in this frame's parent.
     */
    public function child(int|float $x, int|float $y, float $rotationDegrees): self
    {
        $rad = deg2rad($rotationDegrees);
        $cos = cos($rad);
        $sin = sin($rad);

        return new self(
            a: $this->a * $cos + $this->c * $sin,
            b: $this->b * $cos + $this->d * $sin,
            c: -$this->a * $sin + $this->c * $cos,
            d: -$this->b * $sin + $this->d * $cos,
            e: $this->a * $x + $this->c * $y + $this->e,
            f: $this->b * $x + $this->d * $y + $this->f,
        );
    }

    /**
     * @return array{0: float, 1: float}
     */
    public function apply(int|float $x, int|float $y): array
    {
        return [
            $this->a * $x + $this->c * $y + $this->e,
            $this->b * $x + $this->d * $y + $this->f,
        ];
    }
}
