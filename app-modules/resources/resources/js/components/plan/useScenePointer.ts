import type { Ref } from 'vue';
import { panFrom, pinch, svgToWorld, zoomAt } from './transform';
import type { Point, View, Viewport } from './transform';

/**
 * Pointer, wheel and pinch handling for `SeatPlanScene.vue` (design §7.4),
 * split into its own file so the component itself — view wiring, category
 * symbols, hatching, labels — stays near the project's "extract before
 * ~300 lines" guideline (CLAUDE_extra.md). Framework-aware (touches
 * `SVGSVGElement`, `requestAnimationFrame`), so it is deliberately outside
 * the pure-module directory Task 38 covers with vitest: there is no pure
 * math left here once the pan/zoom/pinch formulas themselves live in
 * transform.ts — only DOM event wiring, exercised end to end by Task 40's
 * `SeatPickPageTest`.
 *
 * One map of active pointers in raw viewBox coordinates; a single pointer
 * pans (RAF-flushed, the jitter fix `transform.ts`'s `panFrom` documents),
 * a second pointer switches to a pinch (`transform.ts`'s `pinch`), and
 * wheel zooms anchored at the cursor. `view` is the caller's reactive
 * object, mutated in place so the caller's own template keeps working
 * without extra plumbing.
 */
export function useScenePointer(
    svgRef: Ref<SVGSVGElement | null>,
    view: View,
    viewport: Ref<Viewport>,
    minZoom: Ref<number>,
    pxPerUnit: Ref<number>,
) {
    const pointers = new Map<number, Point>();
    let pan: {
        startSvg: Point;
        startPan: Point;
        zoom: number;
        pending: Point;
        raf: number | null;
    } | null = null;
    let pinchStart: {
        view: View;
        a: Point;
        b: Point;
        ids: [number, number];
    } | null = null;
    let moved = false;

    function svgPointRaw(event: { clientX: number; clientY: number }): Point {
        const svg = svgRef.value;

        if (!svg) {
            return { x: 0, y: 0 };
        }

        const pt = svg.createSVGPoint();
        pt.x = event.clientX;
        pt.y = event.clientY;
        const ctm = svg.getScreenCTM();

        if (!ctm) {
            return { x: 0, y: 0 };
        }

        const p = pt.matrixTransform(ctm.inverse());

        return { x: p.x, y: p.y };
    }

    function flushPan(): void {
        if (!pan) {
            return;
        }

        pan.raf = null;
        view.panX = pan.pending.x;
        view.panY = pan.pending.y;
    }

    function stopPan(): void {
        if (pan?.raf !== null && pan?.raf !== undefined) {
            cancelAnimationFrame(pan.raf);
            flushPan();
        }

        pan = null;
    }

    function onPointerDown(event: PointerEvent): void {
        const svg = svgRef.value;

        if (!svg) {
            return;
        }

        svg.setPointerCapture(event.pointerId);
        pointers.set(event.pointerId, svgPointRaw(event));
        moved = false;

        if (pointers.size === 1) {
            pinchStart = null;
            pan = {
                startSvg: svgPointRaw(event),
                startPan: { x: view.panX, y: view.panY },
                zoom: view.zoom,
                pending: { x: view.panX, y: view.panY },
                raf: null,
            };

            return;
        }

        if (pointers.size === 2) {
            stopPan();
            const [[idA, a], [idB, b]] = [...pointers.entries()];
            pinchStart = { view: { ...view }, a, b, ids: [idA, idB] };
        }
    }

    function onPointerMove(event: PointerEvent): void {
        if (!pointers.has(event.pointerId)) {
            return;
        }

        const current = svgPointRaw(event);
        pointers.set(event.pointerId, current);

        if (pinchStart) {
            const nowA = pointers.get(pinchStart.ids[0]);
            const nowB = pointers.get(pinchStart.ids[1]);

            if (nowA && nowB) {
                Object.assign(
                    view,
                    pinch(
                        pinchStart.view,
                        pinchStart.a,
                        pinchStart.b,
                        nowA,
                        nowB,
                        viewport.value,
                        minZoom.value,
                    ),
                );
                moved = true;
            }

            return;
        }

        if (!pan) {
            return;
        }

        pan.pending = panFrom(pan.startSvg, current, pan.startPan, pan.zoom);

        if (
            Math.hypot(current.x - pan.startSvg.x, current.y - pan.startSvg.y) *
                pxPerUnit.value >
            4
        ) {
            moved = true;
        }

        if (pan.raf === null) {
            pan.raf = requestAnimationFrame(flushPan);
        }
    }

    function onPointerUp(event: PointerEvent): void {
        pointers.delete(event.pointerId);

        try {
            svgRef.value?.releasePointerCapture(event.pointerId);
        } catch {
            /* already released */
        }

        stopPan();
        pinchStart = null;
    }

    function onWheel(event: WheelEvent): void {
        const anchor = svgToWorld(svgPointRaw(event), view, viewport.value);
        Object.assign(
            view,
            zoomAt(view, anchor, event.deltaY > 0 ? 0.9 : 1.1, minZoom.value),
        );
    }

    function zoomStep(factor: number): void {
        Object.assign(
            view,
            zoomAt(view, { x: view.panX, y: view.panY }, factor, minZoom.value),
        );
    }

    /**
     * A client point (`event.clientX/clientY`) in plan millimetres. The two
     * conversions a host would otherwise repeat — screen → viewBox through
     * the CTM, viewBox → world through the current pan and zoom — in one
     * call, so the host never reads `view` itself.
     */
    function svgPoint(clientX: number, clientY: number): Point {
        return svgToWorld(
            svgPointRaw({ clientX, clientY }),
            view,
            viewport.value,
        );
    }

    /** Whether the gesture in progress (or the one that just ended) dragged past the click threshold. */
    function wasDragged(): boolean {
        return moved;
    }

    return {
        onPointerDown,
        onPointerMove,
        onPointerUp,
        onWheel,
        zoomStep,
        svgPoint,
        wasDragged,
    };
}
