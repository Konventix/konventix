/**
 * The client half of a report's context (design §5, §6.3) as plain data and
 * pure functions, deliberately separated from the composable that wires it
 * to Inertia and to `window`.
 *
 * Two reasons for the split, and the second is the one that matters. The
 * ring buffer is the piece with actual rules — five entries, newest last,
 * an id kept only when it looks like a correlation id — so it is the piece
 * worth testing, and testing it must not require a DOM or a router. And
 * everything here is *untrusted* by the time it reaches the server
 * (`SupportClientContextData`): the bounds below are a courtesy to the
 * endpoint, never the enforcement. The server re-validates all of it and
 * overwrites every field it can derive itself (D-SUP-04).
 */
import { trans } from 'laravel-vue-i18n';

/** Design §6.3: "I clicked pay and ended up here" in five hops. */
export const VISIT_BUFFER_SIZE = 5;

/**
 * The host's own shape for a correlation id (ADR-0056), mirrored here and
 * in `SupportContextData::CORRELATION_ID` on the server. A value that does
 * not match is dropped rather than sent: a report carrying a made-up id
 * points at log lines that are not the reporter's.
 */
export const CORRELATION_ID_PATTERN = /^[A-Za-z0-9._-]{8,64}$/;

/** A URL is a locator, not a payload. */
export const URL_MAX = 2048;

/** A stack trace is a hint here; the correlation id is the evidence. */
export const CLIENT_ERROR_MAX = 2000;

export interface SupportVisit {
    url: string;
    correlationId: string | null;
    at: string | null;
}

export interface SupportViewport {
    width: number;
    height: number;
    dpr: number;
}

export interface SupportClientContext {
    pageCorrelationId: string | null;
    url: string | null;
    previousUrl: string | null;
    previousCorrelationId: string | null;
    recentVisits: SupportVisit[];
    viewport: SupportViewport | null;
    clientSentAt: string | null;
    lastClientError: string | null;
}

/** Everything the composable accumulates over one page load. */
export interface SupportContextState {
    visits: SupportVisit[];
    lastClientError: string | null;
}

export function emptyContextState(): SupportContextState {
    return { visits: [], lastClientError: null };
}

/**
 * An id we are willing to quote, or null. Anything that is not a string, is
 * empty, or does not match the host's own rule is dropped — never
 * truncated into shape, because half an id is a wrong id.
 */
export function acceptCorrelationId(value: unknown): string | null {
    return typeof value === 'string' && CORRELATION_ID_PATTERN.test(value)
        ? value
        : null;
}

/** Bounded, and never "…" — the tail of a URL is not a sentence. */
export function boundedUrl(value: string | null | undefined): string | null {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    return value.slice(0, URL_MAX);
}

/**
 * The hash is dropped and the query is kept (design §5): a query string is
 * usually what made the page behave the way it did, while a fragment never
 * reaches the server and so explains nothing about what it did.
 */
export function reportableUrl(href: string): string | null {
    const withoutHash = href.split('#')[0] ?? '';

    return boundedUrl(withoutHash);
}

/**
 * Push a visit onto the ring buffer and keep the newest five.
 *
 * Returns a new array rather than mutating: the state is reactive and a
 * shared array that changed in place would not tell anyone.
 */
export function rememberVisit(
    visits: SupportVisit[],
    visit: SupportVisit,
): SupportVisit[] {
    return [...visits, visit].slice(-VISIT_BUFFER_SIZE);
}

/**
 * One line out of a caught error: message first, then as much of the stack
 * as fits. Only ever the *last* one is kept (design §6.3) — this is a hint
 * about what the person saw, not an error-reporting pipeline, and nothing
 * here ever makes a network request of its own.
 */
export function describeClientError(
    message: unknown,
    stack?: unknown,
): string | null {
    const head = typeof message === 'string' && message !== '' ? message : null;
    const tail = typeof stack === 'string' && stack !== '' ? stack : null;

    if (head === null && tail === null) {
        return null;
    }

    return [head, tail].filter(Boolean).join('\n').slice(0, CLIENT_ERROR_MAX);
}

/**
 * The previous page is the entry before the current one, which is why the
 * buffer is read from the end: the newest entry *is* the page the dialog
 * was opened on.
 */
export function previousVisit(visits: SupportVisit[]): SupportVisit | null {
    return visits.length >= 2 ? (visits[visits.length - 2] ?? null) : null;
}

/**
 * The payload the dialog posts. Every field here is one the server cannot
 * derive for itself; anything the server *can* derive is absent on purpose,
 * because sending it would only invite somebody to send a different one.
 */
export function snapshotContext(
    state: SupportContextState,
    options: {
        pageCorrelationId: unknown;
        href: string | null;
        viewport: SupportViewport | null;
        now: string;
    },
): SupportClientContext {
    const previous = previousVisit(state.visits);

    return {
        pageCorrelationId: acceptCorrelationId(options.pageCorrelationId),
        url: options.href === null ? null : reportableUrl(options.href),
        previousUrl: previous === null ? null : boundedUrl(previous.url),
        previousCorrelationId:
            previous === null
                ? null
                : acceptCorrelationId(previous.correlationId),
        recentVisits: state.visits,
        viewport: options.viewport,
        clientSentAt: options.now,
        lastClientError: state.lastClientError,
    };
}

/**
 * What the "What we send with your report" section lists, in the order it
 * lists it (design §6.2 item 7, §12).
 *
 * It is a literal list rather than something derived from the payload, and
 * that is the honest choice: the person is being told what the *server*
 * stores, which includes the fields the browser never sends — the release,
 * the brand, the route, the account. A list generated from the request body
 * would quietly stop mentioning them.
 */
export function disclosedFields(context: SupportClientContext): {
    label: string;
    value: string;
}[] {
    const notAvailable = trans('not available');

    return [
        {
            label: trans('Page reference'),
            value: context.pageCorrelationId ?? notAvailable,
        },
        { label: trans('Page address'), value: context.url ?? notAvailable },
        {
            label: trans('Previous page'),
            value: context.previousUrl ?? trans('none in this visit'),
        },
        {
            label: trans('Your last few pages'),
            value: trans(':count of :max', {
                count: String(context.recentVisits.length),
                max: String(VISIT_BUFFER_SIZE),
            }),
        },
        {
            label: trans('Window size'),
            value:
                context.viewport === null
                    ? notAvailable
                    : trans(':width × :height at :dpr×', {
                          width: String(context.viewport.width),
                          height: String(context.viewport.height),
                          dpr: String(context.viewport.dpr),
                      }),
        },
        {
            label: trans('Last error your browser saw'),
            value:
                context.lastClientError === null
                    ? trans('none')
                    : trans('yes, the message'),
        },
        {
            label: trans('From the server'),
            value: trans(
                'release, brand, page route, your account, browser and time of arrival',
            ),
        },
        {
            label: trans('Never sent'),
            value: trans(
                'screenshots, what you typed on the page, cookies, your IP address',
            ),
        },
    ];
}
