import type { Point } from './document';

/**
 * Grid snapping in plan millimetres (design §5): `round(v / step) * step`
 * with the four steps the plan's `snap_step` column allows.
 */
export type SnapStep = 1 | 10 | 100 | 1000;

export const SNAP_STEPS: readonly SnapStep[] = [1, 10, 100, 1000];

export function snapValue(value: number, step: SnapStep): number {
    return Math.round(value / step) * step;
}

export function snapPoint(point: Point, step: SnapStep): Point {
    return { x: snapValue(point.x, step), y: snapValue(point.y, step) };
}

/**
 * The delta that moves `anchor` by `delta` and lands it on the grid. A
 * drag snaps its destination, not its distance: an element sitting on the
 * grid stays on it, and one that was off the grid is pulled onto it.
 */
export function snapDelta(anchor: Point, delta: Point, step: SnapStep): Point {
    const target = snapPoint(
        { x: anchor.x + delta.x, y: anchor.y + delta.y },
        step,
    );

    return { x: target.x - anchor.x, y: target.y - anchor.y };
}
