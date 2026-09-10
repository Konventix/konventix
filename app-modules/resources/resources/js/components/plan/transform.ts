/**
 * Pan/zoom for the seat plan scene as one world transform (design §7.4).
 *
 * The SVG keeps a fixed viewBox of the plan's size in millimetres and
 * every element sits inside one `<g :transform="worldTransform(...)">`.
 * LanCore first mutated the viewBox and measured the cost — every
 * pointermove re-laid out every child (text metrics, stroke widths, hit
 * testing) — before moving to a single transform on one element, which the
 * browser composites (EditorCanvas.vue:29-47). Ported here with mm units.
 *
 * Mapping, with `c` the viewBox centre:
 *   svg   = (world - pan) * zoom + c
 *   world = (svg - c) / zoom + pan
 * so `pan` is the world point shown at the centre of the viewport.
 *
 * Pure functions; the scene owns the reactive state.
 */
export type Point = { x: number; y: number };
export type View = { panX: number; panY: number; zoom: number };
export type Viewport = { width: number; height: number };
export type Bbox = { minX: number; minY: number; maxX: number; maxY: number };

export const MAX_ZOOM = 8;
export const ABSOLUTE_MIN_ZOOM = 0.05;
export const FIT_PADDING = 1.2;

/** A plan smaller than this still gets a sensible fit (LanCore's 200-unit floor, in mm). */
const MIN_FIT_EXTENT_MM = 1000;

export function viewCenter(viewport: Viewport): Point {
    return { x: viewport.width / 2, y: viewport.height / 2 };
}

export function worldTransform(view: View, viewport: Viewport): string {
    const c = viewCenter(viewport);
    const tx = c.x - view.panX * view.zoom;
    const ty = c.y - view.panY * view.zoom;

    return `translate(${tx} ${ty}) scale(${view.zoom})`;
}

export function worldToSvg(
    world: Point,
    view: View,
    viewport: Viewport,
): Point {
    const c = viewCenter(viewport);

    return {
        x: (world.x - view.panX) * view.zoom + c.x,
        y: (world.y - view.panY) * view.zoom + c.y,
    };
}

export function svgToWorld(svg: Point, view: View, viewport: Viewport): Point {
    const c = viewCenter(viewport);

    return {
        x: (svg.x - c.x) / view.zoom + view.panX,
        y: (svg.y - c.y) / view.zoom + view.panY,
    };
}

/**
 * The pan for a drag from `startSvg` to `currentSvg`, both in raw viewBox
 * coordinates, against the pan and zoom captured at pointer-down.
 *
 * Raw coordinates on purpose (EditorCanvas.vue:421-431): converting the
 * pointer to world space reads the pan being written, so each frame's
 * delta is measured against a frame that already moved — one frame of
 * under-correction per frame, which reads as jitter.
 */
export function panFrom(
    startSvg: Point,
    currentSvg: Point,
    startPan: Point,
    zoom: number,
): Point {
    return {
        x: startPan.x - (currentSvg.x - startSvg.x) / zoom,
        y: startPan.y - (currentSvg.y - startSvg.y) / zoom,
    };
}

export function clampZoom(
    zoom: number,
    minZoom: number,
    maxZoom: number = MAX_ZOOM,
): number {
    return Math.min(Math.max(zoom, minZoom), maxZoom);
}

/**
 * Zoom by `factor` with the world point `anchor` staying where it is on
 * screen (EditorCanvas.vue:471-497). Returns the same object when the
 * clamp swallows the whole step, so a watcher can skip a no-op.
 */
export function zoomAt(
    view: View,
    anchor: Point,
    factor: number,
    minZoom: number,
    maxZoom: number = MAX_ZOOM,
): View {
    const zoom = clampZoom(view.zoom * factor, minZoom, maxZoom);
    const actual = zoom / view.zoom;

    if (actual === 1) {
        return view;
    }

    return {
        zoom,
        panX: anchor.x + (view.panX - anchor.x) / actual,
        panY: anchor.y + (view.panY - anchor.y) / actual,
    };
}

export function fitZoom(
    bbox: Bbox,
    viewport: Viewport,
    padding: number = FIT_PADDING,
): number {
    const width = Math.max(bbox.maxX - bbox.minX, MIN_FIT_EXTENT_MM);
    const height = Math.max(bbox.maxY - bbox.minY, MIN_FIT_EXTENT_MM);

    return Math.min(
        viewport.width / (width * padding),
        viewport.height / (height * padding),
    );
}

/**
 * The zoom below which nothing more comes into view: the plan just fits
 * with padding. Capped at 1 so a tiny plan still opens at full size and
 * floored so a degenerate one never yields zero (useEditorStore.ts:62-122).
 */
export function minZoomFor(
    bbox: Bbox | null,
    viewport: Viewport,
    padding: number = FIT_PADDING,
): number {
    if (bbox === null) {
        return ABSOLUTE_MIN_ZOOM;
    }

    return Math.max(
        ABSOLUTE_MIN_ZOOM,
        Math.min(fitZoom(bbox, viewport, padding), 1),
    );
}

export function fitToBbox(
    bbox: Bbox,
    viewport: Viewport,
    options: { padding?: number; minZoom?: number; maxZoom?: number } = {},
): View {
    const {
        padding = FIT_PADDING,
        minZoom = ABSOLUTE_MIN_ZOOM,
        maxZoom = MAX_ZOOM,
    } = options;

    return {
        panX: (bbox.minX + bbox.maxX) / 2,
        panY: (bbox.minY + bbox.maxY) / 2,
        zoom: clampZoom(fitZoom(bbox, viewport, padding), minZoom, maxZoom),
    };
}

function distance(a: Point, b: Point): number {
    return Math.hypot(b.x - a.x, b.y - a.y);
}

function midpoint(a: Point, b: Point): Point {
    return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
}

/**
 * Two-finger pinch from the view at gesture start: the zoom scales by the
 * ratio of finger distances, and the world point that was under the start
 * midpoint is placed under the current midpoint — so a pinch that also
 * drifts pans as well. All points are raw viewBox coordinates.
 */
export function pinch(
    start: View,
    startA: Point,
    startB: Point,
    nowA: Point,
    nowB: Point,
    viewport: Viewport,
    minZoom: number,
    maxZoom: number = MAX_ZOOM,
): View {
    const startDistance = distance(startA, startB);

    if (startDistance === 0) {
        return start;
    }

    const zoom = clampZoom(
        start.zoom * (distance(nowA, nowB) / startDistance),
        minZoom,
        maxZoom,
    );
    const anchorWorld = svgToWorld(midpoint(startA, startB), start, viewport);
    const nowMid = midpoint(nowA, nowB);
    const c = viewCenter(viewport);

    return {
        zoom,
        panX: anchorWorld.x - (nowMid.x - c.x) / zoom,
        panY: anchorWorld.y - (nowMid.y - c.y) / zoom,
    };
}
