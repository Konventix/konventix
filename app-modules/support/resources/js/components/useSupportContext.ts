import { router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import {
    describeClientError,
    emptyContextState,
    rememberVisit,
    reportableUrl,
    snapshotContext,
} from './supportContext';
import type {
    SupportClientContext,
    SupportContextState,
    SupportViewport,
} from './supportContext';

/**
 * The launcher's memory of this page load (design §6.3).
 *
 * Module-level, because the launcher is mounted once per layout and the
 * buffer has to survive the client-side navigations it is there to record —
 * a per-component ref would be thrown away by the first visit and the
 * buffer would forever hold exactly one entry, which is the entry the
 * dialog already knows.
 *
 * **Nothing here persists beyond the page load.** No storage, no cookie, no
 * network. A reload starts over, which is the correct behaviour for a
 * feature whose whole promise to the person is "we send what is on this
 * page and nothing else" (design §11).
 */
const state = reactive<SupportContextState>(emptyContextState());

let installed = false;

/**
 * The window's size as the person sees it, which is the one line of context
 * a screenshot would have carried — and screenshots are not collected
 * (D-SUP-08). Rounded, because a fractional device pixel ratio is noise and
 * a fractional width does not exist.
 */
function viewport(): SupportViewport | null {
    if (typeof window === 'undefined') {
        return null;
    }

    return {
        width: Math.round(window.innerWidth),
        height: Math.round(window.innerHeight),
        dpr: Math.round((window.devicePixelRatio || 1) * 100) / 100,
    };
}

function correlationIdOf(props: Record<string, unknown> | undefined): unknown {
    return props?.correlationId;
}

/**
 * Records the page we are on right now. Called once on install and again
 * after every Inertia visit, so the buffer reads as the path the person
 * actually walked rather than as a list of pages that happened to load.
 */
function recordCurrentVisit(correlationId: unknown): void {
    if (typeof window === 'undefined') {
        return;
    }

    const url = reportableUrl(window.location.href);

    if (url === null) {
        return;
    }

    state.visits = rememberVisit(state.visits, {
        url,
        correlationId: typeof correlationId === 'string' ? correlationId : null,
        at: new Date().toISOString(),
    });
}

/**
 * Registers the two listeners exactly once.
 *
 * The error handlers keep only the *last* message and stack and do nothing
 * else — they never report, never retry, never touch the network. They
 * exist so that a person who says "it just broke" is not the only source of
 * information about what broke. `window.onerror` is assigned through
 * `addEventListener`, never by overwriting the property, so nothing else
 * that installed a handler loses it.
 */
function install(): void {
    if (installed || typeof window === 'undefined') {
        return;
    }

    installed = true;

    recordCurrentVisit(correlationIdOf(usePage().props));

    router.on('success', (event) => {
        recordCurrentVisit(correlationIdOf(event.detail.page.props));
    });

    window.addEventListener('error', (event: ErrorEvent) => {
        state.lastClientError =
            describeClientError(event.message, event.error?.stack) ??
            state.lastClientError;
    });

    window.addEventListener(
        'unhandledrejection',
        (event: PromiseRejectionEvent) => {
            const reason: unknown = event.reason;

            state.lastClientError =
                describeClientError(
                    reason instanceof Error ? reason.message : String(reason),
                    reason instanceof Error ? reason.stack : undefined,
                ) ?? state.lastClientError;
        },
    );
}

export function useSupportContext() {
    install();

    function snapshot(): SupportClientContext {
        return snapshotContext(state, {
            pageCorrelationId: correlationIdOf(usePage().props),
            href: typeof window === 'undefined' ? null : window.location.href,
            viewport: viewport(),
            now: new Date().toISOString(),
        });
    }

    return { state, snapshot };
}
