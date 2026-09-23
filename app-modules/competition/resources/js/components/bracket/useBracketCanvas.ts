import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import {
    layoutBracket,
    MATCH_HEIGHT,
    MATCH_WIDTH,
    matchAt,
    sideName,
} from './bracketModel';
import type { BracketSection, PlacedMatch } from './bracketModel';

/**
 * The bracket drawn on a canvas, with pan and zoom (design §10, D-CMP-22) —
 * ported from LanBrackets' `useBracketCanvas.ts`, the renderer the inventory
 * calls "the single most valuable piece of frontend in either repo".
 *
 * What changed in the port, and why:
 *
 * - **Colours are the brand's semantic tokens** (ADR-0008), read from the
 *   canvas element's computed style on every draw — so a brand's overrides
 *   and a switch to dark mode reach the canvas as they reach the rest of the
 *   page. LanBrackets hard-coded a slate palette.
 * - **The lines are the progression graph** (`BracketData.connections`),
 *   not a guess from positions: LanBrackets joined match `i` to match
 *   `i / 2` of the next round, which is wrong for any bracket whose graph is
 *   not that shape. Winner edges only, as LanBrackets drew them; a loser's
 *   drop into the losers bracket would cross the whole canvas.
 * - **It draws when something changes**, not on every animation frame: the
 *   pulsing "ready" glow is gone (a still border says the same thing, and a
 *   perpetual animation is what `prefers-reduced-motion` asks pages not to
 *   run), and so are the medal emoji — the standings table beside the
 *   bracket says who placed where.
 * - Pointer events replace the separate mouse and touch handlers, with the
 *   two-finger pinch kept. A click (a press that did not drag) on a match
 *   opens it.
 *
 * The canvas is a picture. The table beside it carries the same pairings
 * and is the keyboard- and screen-reader-accessible form (ADR-0055).
 */
type Palette = {
    surface: string;
    raised: string;
    border: string;
    text: string;
    muted: string;
    accent: string;
    success: string;
    warning: string;
    danger: string;
    font: string;
};

const MIN_SCALE = 0.2;
const MAX_SCALE = 3;
/** A press that moved less than this is a click, not a drag. */
const CLICK_SLOP = 4;

function palette(element: HTMLElement): Palette {
    const style = getComputedStyle(element);
    const token = (name: string) => style.getPropertyValue(name).trim();

    return {
        surface: token('--surface'),
        raised: token('--surface-raised'),
        border: token('--border'),
        text: token('--text'),
        muted: token('--text-muted'),
        accent: token('--accent'),
        success: token('--success'),
        warning: token('--warning'),
        danger: token('--danger'),
        font: style.fontFamily,
    };
}

function roundRect(
    ctx: CanvasRenderingContext2D,
    x: number,
    y: number,
    w: number,
    h: number,
    r: number,
): void {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + w - r, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r);
    ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r);
    ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
}

function truncate(text: string, maxLen: number): string {
    return text.length > maxLen ? text.slice(0, maxLen - 1) + '…' : text;
}

/** Both sides known, nothing recorded: the match can be played now. */
function isReady(match: Konventix.Competition.Data.BracketMatchData): boolean {
    return (
        (match.state === 'pending' || match.state === 'scheduled') &&
        match.sides.length >= 2 &&
        match.sides.every((side) => side.entrantId !== null)
    );
}

function drawConnections(
    ctx: CanvasRenderingContext2D,
    placed: PlacedMatch[],
    connections: Konventix.Competition.Data.BracketConnectionData[],
    colors: Palette,
): void {
    const byId = new Map(placed.map((item) => [item.match.id, item]));
    const rowHeight = MATCH_HEIGHT / 2;

    for (const edge of connections) {
        const from = byId.get(edge.sourceMatchId);
        const to = byId.get(edge.targetMatchId);

        if (edge.outcome !== 'winner' || !from || !to) {
            continue;
        }

        const fromX = from.x + MATCH_WIDTH;
        const fromY = from.y + MATCH_HEIGHT / 2;
        const toX = to.x;
        // Into the row of the slot it fills, not the middle of the box.
        const toY = to.y + (edge.targetSlot - 1) * rowHeight + rowHeight / 2;
        const midX = (fromX + toX) / 2;

        ctx.beginPath();
        ctx.strokeStyle =
            from.match.state === 'finished' ? colors.accent : colors.border;
        ctx.lineWidth = 1.5;
        ctx.moveTo(fromX, fromY);
        ctx.bezierCurveTo(midX, fromY, midX, toY, toX, toY);
        ctx.stroke();
    }
}

function drawSide(
    ctx: CanvasRenderingContext2D,
    x: number,
    y: number,
    side: Konventix.Competition.Data.BracketSideData | undefined,
    colors: Palette,
): void {
    const rowHeight = MATCH_HEIGHT / 2;
    const baseline = y + rowHeight / 2 + 4;
    const winner = side?.winner === true;

    ctx.font = `${winner ? 'bold ' : ''}11px ${colors.font}`;
    ctx.fillStyle = winner
        ? colors.success
        : side?.teamName
          ? colors.text
          : colors.muted;
    ctx.textAlign = 'left';
    ctx.fillText(truncate(sideName(side), 24), x + 8, baseline);

    if (side?.score !== null && side?.score !== undefined) {
        ctx.font = `bold 12px ${colors.font}`;
        ctx.fillStyle = winner ? colors.success : colors.text;
        ctx.textAlign = 'right';
        ctx.fillText(String(side.score), x + MATCH_WIDTH - 8, baseline);
        ctx.textAlign = 'left';
    }
}

function drawMatch(
    ctx: CanvasRenderingContext2D,
    item: PlacedMatch,
    colors: Palette,
): void {
    const { match, x, y } = item;

    ctx.fillStyle = colors.raised;
    roundRect(ctx, x, y, MATCH_WIDTH, MATCH_HEIGHT, 6);
    ctx.fill();

    const emphasis = match.disputed
        ? colors.danger
        : match.state === 'live'
          ? colors.accent
          : isReady(match)
            ? colors.warning
            : colors.border;

    ctx.strokeStyle = emphasis;
    ctx.lineWidth = emphasis === colors.border ? 1 : 2;
    roundRect(ctx, x, y, MATCH_WIDTH, MATCH_HEIGHT, 6);
    ctx.stroke();

    const first = match.sides.find((side) => side.position === 1);
    const second = match.sides.find((side) => side.position === 2);

    drawSide(ctx, x, y, first, colors);

    ctx.beginPath();
    ctx.strokeStyle = colors.border;
    ctx.lineWidth = 0.5;
    ctx.moveTo(x + 4, y + MATCH_HEIGHT / 2);
    ctx.lineTo(x + MATCH_WIDTH - 4, y + MATCH_HEIGHT / 2);
    ctx.stroke();

    drawSide(ctx, x, y + MATCH_HEIGHT / 2, second, colors);
}

function drawSectionLabels(
    ctx: CanvasRenderingContext2D,
    placed: PlacedMatch[],
    colors: Palette,
): void {
    const tops = new Map<BracketSection, { x: number; y: number }>();

    for (const item of placed) {
        const current = tops.get(item.section);

        tops.set(item.section, {
            x: Math.min(current?.x ?? Infinity, item.x),
            y: Math.min(current?.y ?? Infinity, item.y),
        });
    }

    ctx.font = `bold 13px ${colors.font}`;
    ctx.fillStyle = colors.muted;
    ctx.textAlign = 'left';

    for (const [section, { x, y }] of tops) {
        if (section.label !== null) {
            ctx.fillText(section.label.toUpperCase(), x, y - 10);
        }
    }
}

export type UseBracketCanvasOptions = {
    sections: Readonly<Ref<BracketSection[]>>;
    connections: Readonly<
        Ref<Konventix.Competition.Data.BracketConnectionData[]>
    >;
    onOpen: (matchId: string) => void;
};

export function useBracketCanvas(options: UseBracketCanvasOptions) {
    const canvas = ref<HTMLCanvasElement | null>(null);

    const offsetX = ref(40);
    const offsetY = ref(40);
    const scale = ref(1);
    const isDragging = ref(false);

    let placed: PlacedMatch[] = [];
    let frame = 0;
    let resizeObserver: ResizeObserver | null = null;
    let themeObserver: MutationObserver | null = null;

    /** Active pointers, for a drag with one and a pinch with two. */
    const pointers = new Map<number, { x: number; y: number }>();
    let dragStart = { x: 0, y: 0, offsetX: 0, offsetY: 0 };
    let pinchStart = { distance: 0, scale: 1 };
    let moved = 0;

    function draw(): void {
        frame = 0;

        const element = canvas.value;
        const ctx = element?.getContext('2d');

        if (!element || !ctx) {
            return;
        }

        const colors = palette(element);
        const dpr = window.devicePixelRatio || 1;
        const w = element.clientWidth;
        const h = element.clientHeight;
        element.width = w * dpr;
        element.height = h * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        ctx.fillStyle = colors.surface;
        ctx.fillRect(0, 0, w, h);

        ctx.save();
        ctx.translate(offsetX.value, offsetY.value);
        ctx.scale(scale.value, scale.value);

        drawConnections(ctx, placed, options.connections.value, colors);

        for (const item of placed) {
            drawMatch(ctx, item, colors);
        }

        drawSectionLabels(ctx, placed, colors);

        ctx.restore();
    }

    /** One draw per animation frame at most, however many changes arrive. */
    function redraw(): void {
        if (frame === 0 && typeof window !== 'undefined') {
            frame = requestAnimationFrame(draw);
        }
    }

    function fitToScreen(): void {
        const element = canvas.value;

        if (!element || placed.length === 0) {
            return;
        }

        const minX = Math.min(...placed.map((p) => p.x));
        const maxX = Math.max(...placed.map((p) => p.x + MATCH_WIDTH));
        const minY = Math.min(...placed.map((p) => p.y)) - 24;
        const maxY = Math.max(...placed.map((p) => p.y + MATCH_HEIGHT));

        const contentW = maxX - minX + 80;
        const contentH = maxY - minY + 80;

        scale.value = Math.min(
            element.clientWidth / contentW,
            element.clientHeight / contentH,
            2,
        );
        offsetX.value =
            (element.clientWidth - contentW * scale.value) / 2 -
            minX * scale.value +
            40 * scale.value;
        offsetY.value =
            (element.clientHeight - contentH * scale.value) / 2 -
            minY * scale.value +
            40 * scale.value;
    }

    function zoomBy(factor: number, cx?: number, cy?: number): void {
        const element = canvas.value;

        if (!element) {
            return;
        }

        const next = Math.min(
            MAX_SCALE,
            Math.max(MIN_SCALE, scale.value * factor),
        );
        const mx = cx ?? element.clientWidth / 2;
        const my = cy ?? element.clientHeight / 2;

        offsetX.value = mx - (mx - offsetX.value) * (next / scale.value);
        offsetY.value = my - (my - offsetY.value) * (next / scale.value);
        scale.value = next;
    }

    function zoomIn(): void {
        zoomBy(1.2);
    }

    function zoomOut(): void {
        zoomBy(1 / 1.2);
    }

    function local(event: PointerEvent | WheelEvent): { x: number; y: number } {
        const rect = canvas.value!.getBoundingClientRect();

        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    }

    function onPointerDown(event: PointerEvent): void {
        canvas.value?.setPointerCapture(event.pointerId);
        pointers.set(event.pointerId, local(event));

        if (pointers.size === 1) {
            isDragging.value = true;
            moved = 0;
            dragStart = {
                ...local(event),
                offsetX: offsetX.value,
                offsetY: offsetY.value,
            };
        } else if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            pinchStart = {
                distance: Math.hypot(b.x - a.x, b.y - a.y),
                scale: scale.value,
            };
        }
    }

    function onPointerMove(event: PointerEvent): void {
        if (!pointers.has(event.pointerId)) {
            return;
        }

        pointers.set(event.pointerId, local(event));

        if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            const distance = Math.hypot(b.x - a.x, b.y - a.y);

            if (pinchStart.distance > 0) {
                zoomBy(
                    (pinchStart.scale * (distance / pinchStart.distance)) /
                        scale.value,
                    (a.x + b.x) / 2,
                    (a.y + b.y) / 2,
                );
            }

            moved = CLICK_SLOP;

            return;
        }

        const point = local(event);
        moved = Math.max(
            moved,
            Math.hypot(point.x - dragStart.x, point.y - dragStart.y),
        );
        offsetX.value = dragStart.offsetX + (point.x - dragStart.x);
        offsetY.value = dragStart.offsetY + (point.y - dragStart.y);
    }

    function onPointerUp(event: PointerEvent): void {
        const wasClick = pointers.size === 1 && moved < CLICK_SLOP;
        pointers.delete(event.pointerId);
        isDragging.value = pointers.size > 0;

        if (!wasClick) {
            return;
        }

        const point = local(event);
        const hit = matchAt(
            placed,
            (point.x - offsetX.value) / scale.value,
            (point.y - offsetY.value) / scale.value,
        );

        if (hit) {
            options.onOpen(hit.match.id);
        }
    }

    function onWheel(event: WheelEvent): void {
        event.preventDefault();
        const point = local(event);
        zoomBy(event.deltaY > 0 ? 0.9 : 1.1, point.x, point.y);
    }

    watch(
        options.sections,
        (sections) => {
            placed = layoutBracket(sections);
            redraw();
        },
        { immediate: true, deep: true },
    );

    watch([offsetX, offsetY, scale, options.connections], redraw);

    onMounted(() => {
        fitToScreen();
        redraw();

        // A resize changes the pixel size; a theme switch (the root's
        // `dark` class) or a brand's token override changes every colour the
        // palette reads. Both are a redraw.
        resizeObserver = new ResizeObserver(redraw);
        resizeObserver.observe(canvas.value!);
        themeObserver = new MutationObserver(redraw);
        themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class', 'style'],
        });
    });

    onBeforeUnmount(() => {
        resizeObserver?.disconnect();
        themeObserver?.disconnect();
        cancelAnimationFrame(frame);
    });

    return {
        canvas,
        isDragging,
        scale,
        fitToScreen,
        zoomIn,
        zoomOut,
        onPointerDown,
        onPointerMove,
        onPointerUp,
        onWheel,
    };
}
