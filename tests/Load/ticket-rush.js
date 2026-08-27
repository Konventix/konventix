// The rush (ADR-0047 point 6): 1,000 arrivals against the seeded "Rush"
// brand (`database/seeders/Demo/DemoRushSeeder.php`, `konventix:seed --rush`)
// — a capacity-300 instant-settlement ticket, a 200-unit shop item, and a
// waiting room in front of both. Each VU is one arrival: log in, hit the
// ticket form, queue, get admitted, submit the ticket (which lands in the
// cart, since the type is `instant` and `commerce:shop` is on), buy the
// shirt, check out.
//
// Every request goes straight to `laravel.test` on the compose network
// (the k6 service's DNS name for it) carrying `Host: rush.localhost` so
// Konventix's brand resolution picks the seeded brand — nothing here
// depends on `rush.localhost` actually resolving anywhere. Redirects are
// NEVER auto-followed (`redirects: 0` on every call): several of this
// app's own redirects are absolute URLs to `http://rush.localhost/...`,
// which would send k6's HTTP client looking for a host with no DNS entry.
// Every next hop is built by hand from the previous response's `Location`
// header (or its JSON `redirectTo`), keeping only the path and re-issuing
// it against `BASE_URL` with the same `Host` header.
//
// `EVENT_ID`/`TYPE_ID` are the fixed ULIDs `DemoRushSeeder` pins the rush
// event and its "Rush ticket" type to — see that class's docblock for why
// they are a literal copy here rather than something this script discovers
// live (the registration route requires an admitted waiting-room ticket to
// even *view*, so there is no page to scrape them off of before one).
//
// Every request also carries a per-arrival `X-Forwarded-For` (see
// `forwardedForHeader()`): `IssueWaitingRoomTicket::IP_CAP` refuses a
// sixth *new* ticket from the same source IP inside its rolling window —
// a deliberate anti-abuse guard against one visitor flooding the queue
// with fake arrivals — and every k6 VU shares this container's one real
// IP. Iterations 1–3 (task-14-report.md) missed this and mis-attributed
// the resulting wall of 429s to raw concurrency limits; a synthetic,
// per-arrival forwarded-for address is exactly what a real reverse proxy
// hands the app for 1,000 different visitors, and `TRUSTED_PROXIES`
// already trusts the docker network's private range this container sits
// on, so the header is honoured without any config change.
//
// Run: `docker compose --profile load run --rm k6 run /scripts/ticket-rush.js`
import { check, sleep } from 'k6';
import http from 'k6/http';
import { Counter } from 'k6/metrics';

const BASE_URL = __ENV.RUSH_BASE_URL || 'http://laravel.test';
const HOST = __ENV.RUSH_HOST || 'rush.localhost';

// Copied from database/seeders/Demo/DemoRushSeeder.php — keep in sync.
const EVENT_ID = '01M1206XWZPSXJPXXBNJ9ZYJC0';
const TYPE_ID = '01M1206XWZPSXJPXXBNJ9ZYJC1';

const REGISTER_PATH = `/events/${EVENT_ID}/register/${TYPE_ID}`;

const serverErrors = new Counter('server_errors');
const admitted = new Counter('admitted');

// Every legitimate step in this flow ends in 200 (a page/JSON) or 302 (a
// redirect this script follows by hand) — anything else counts against
// `http_req_failed`, which is what the threshold below reads.
http.setResponseCallback(http.expectedStatuses(200, 302));

// Iteration 4 (task-14-report.md) found and fixed a real bug — every k6 VU
// shares this container's one source IP, and `IssueWaitingRoomTicket`'s
// per-IP cap (five new tickets) refused nearly all of them; the
// `X-Forwarded-For` header above fixes that for good. But fixing it
// exposed the box's other, genuine limit underneath: 1,000 *concurrently
// open* connections against this single-process FrankenPHP/Octane dev
// container queue past k6's own 60s timeout regardless (measured directly:
// 40 concurrent sustains 500+ req/s at p95 ≈ 45ms; a sudden 200 collapses
// to ~22 req/s). So the 1,000 arrivals stay concurrency-capped at `VUS`
// (default 40, this box's own proven-safe number), each VU making
// `ARRIVALS_PER_VU` (default 25) sequential arrivals — every one of the
// 1,000 still logs in, queues, gets admitted, registers, buys the shirt
// and checks out, just not all 1,000 holding a connection open at once.
// `VUS=1000 ARRIVALS_PER_VU=1` reproduces the original all-at-once profile.
const VUS = Number(__ENV.VUS || 40);
const ARRIVALS_PER_VU = Number(__ENV.ARRIVALS_PER_VU || 25);

export const options = {
    scenarios: {
        rush: {
            executor: 'per-vu-iterations',
            vus: VUS,
            iterations: ARRIVALS_PER_VU,
            maxDuration: '20m',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        server_errors: ['count==0'],
    },
};

/**
 * Strips a same-origin Location header (absolute or relative) down to a
 * path k6 can re-request against BASE_URL — see the module docblock on why
 * this script never lets k6 auto-follow a redirect.
 */
function pathOf(location) {
    if (!location) {
        return null;
    }

    const withoutOrigin = location.replace(/^https?:\/\/[^/]+/, '');

    return withoutOrigin === '' ? '/' : withoutOrigin;
}

// Set once per arrival, at the top of the default function — see the
// module docblock on why every request needs a distinct forwarded-for
// address.
let currentForwardedFor = null;

/**
 * A syntactically valid, evenly-distributed private address per arrival
 * index — `10.0.0.0/8` is large enough that 1,000 arrivals never collide,
 * and it is inside the RFC 1918 range `TRUSTED_PROXIES` already trusts.
 */
function forwardedForHeader(n) {
    return `10.${(n >> 16) & 255}.${(n >> 8) & 255}.${n & 255}`;
}

function headers(extra) {
    return Object.assign({ Host: HOST, 'X-Forwarded-For': currentForwardedFor }, extra || {});
}

function get(path, extra) {
    const res = http.get(`${BASE_URL}${path}`, { headers: headers(extra), redirects: 0 });
    recordStatus(res);

    return res;
}

function post(path, body, extra) {
    const res = http.post(`${BASE_URL}${path}`, body, { headers: headers(extra), redirects: 0 });
    recordStatus(res);

    return res;
}

function recordStatus(res) {
    if (res.status >= 500) {
        serverErrors.add(1);
    }
}

/**
 * The raw XSRF-TOKEN cookie value, url-decoded once — exactly what a
 * browser's own XHR client does before sending it back as X-XSRF-TOKEN.
 * Re-read before every POST rather than cached once: logging in
 * regenerates the session (and its CSRF token) the same way Fortify does
 * for a real browser.
 */
function xsrfToken() {
    const jar = http.cookieJar();
    const cookies = jar.cookiesForURL(`${BASE_URL}/`);
    const raw = cookies['XSRF-TOKEN'] && cookies['XSRF-TOKEN'][0];

    return raw ? decodeURIComponent(raw) : null;
}

/**
 * Pulls the Inertia page's JSON out of `<script data-page="app"
 * type="application/json">…</script>` — every server-rendered page in this
 * app carries one, and it is far cheaper than parsing the surrounding HTML.
 */
function inertiaProps(body) {
    const match = body.match(/<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/);

    if (!match) {
        return null;
    }

    try {
        return JSON.parse(match[1]).props;
    } catch {
        return null;
    }
}

export default function () {
    // per-vu-iterations: __VU is 1-based and stable for the VU's whole
    // life, __ITER is 0-based per iteration — together they address a
    // unique arrival out of the 1,000 without any two VUs (or two
    // iterations of the same VU) ever reusing an account or an IP.
    const n = (__VU - 1) * ARRIVALS_PER_VU + __ITER + 1;
    currentForwardedFor = forwardedForHeader(n);
    const email = `rush+${n}@konventix.test`;

    // 1. GET /login — a session and an XSRF-TOKEN cookie, exactly what a
    // fresh browser tab gets.
    get('/login');

    // 2. POST /login.
    let res = post('/login', { email, password: 'password' }, {
        'X-XSRF-TOKEN': xsrfToken(),
    });
    check(res, { 'logged in': (r) => r.status === 302 });

    // 3. GET the ticket form — the waiting-room gate has not seen this
    // visitor yet, so this queues them and redirects to the holding page.
    res = get(REGISTER_PATH);
    check(res, { 'queued': (r) => r.status === 302 });

    // 4. Poll /waiting-room/status every 2s until admitted.
    let redirectTo = null;

    for (let i = 0; i < 90 && redirectTo === null; i++) {
        sleep(2);
        res = get('/waiting-room/status');

        const body = res.status === 200 ? JSON.parse(res.body) : null;

        if (body && body.admitted) {
            redirectTo = pathOf(body.redirectTo) || REGISTER_PATH;
        }
    }

    if (redirectTo === null) {
        // Never admitted inside the poll budget — nothing further to do
        // for this arrival; the failed check below is what the threshold
        // sees.
        check(null, { 'admitted before the poll budget ran out': () => false });

        return;
    }

    admitted.add(1);

    // 5. GET the ticket form again, now admitted — the real form page.
    res = get(redirectTo);
    check(res, { 'ticket form reachable once admitted': (r) => r.status === 200 });

    // 6. POST the registration form. `instant` settlement + `commerce:shop`
    // on lands the ticket straight in the cart (RegistrationController)
    // rather than placing a direct order — every field is nullable
    // (SubmitRegistrationData), so an empty submission is a complete one.
    res = post(REGISTER_PATH, { organizationName: '' }, { 'X-XSRF-TOKEN': xsrfToken() });
    check(res, { 'registration submitted': (r) => r.status === 302 });

    // 7. GET the shop item — the same waiting-room ticket carries through
    // (one door per brand, not one per destination).
    res = get('/shop/rush-shirt');
    check(res, { 'shop reachable': (r) => r.status === 200 });

    const shopProps = inertiaProps(res.body);
    const variant = shopProps && shopProps.product && shopProps.product.variants && shopProps.product.variants[0];

    if (!variant) {
        check(null, { 'shop page carried a variant id': () => false });

        return;
    }

    // 8. POST /cart/lines — add the shirt.
    res = post('/cart/lines', { variantId: variant.id, quantity: 1 }, { 'X-XSRF-TOKEN': xsrfToken() });
    check(res, { 'added to cart': (r) => r.status === 302 });

    // 9. GET /cart — both lines (ticket + shirt) and the offered accounts.
    res = get('/cart');
    check(res, { 'cart reachable': (r) => r.status === 200 });

    const cartProps = inertiaProps(res.body);
    const account = cartProps && cartProps.cart && cartProps.cart.accounts && cartProps.cart.accounts[0];

    if (!account) {
        check(null, { 'cart page carried an offered account': () => false });

        return;
    }

    // 10. POST /cart/checkout — the null-provider account
    // (LocalHostedCheckoutAdapter, local/testing only) "starts" a checkout
    // with no real PSP behind it.
    res = post('/cart/checkout', { account: account.id }, { 'X-XSRF-TOKEN': xsrfToken() });
    check(res, { 'checked out': (r) => r.status === 302 });
}

export function handleSummary(data) {
    return {
        'stdout': JSON.stringify({
            http_req_failed_rate: data.metrics.http_req_failed ? data.metrics.http_req_failed.values.rate : null,
            server_errors: data.metrics.server_errors ? data.metrics.server_errors.values.count : 0,
            admitted: data.metrics.admitted ? data.metrics.admitted.values.count : 0,
            p95_ms: data.metrics.http_req_duration ? data.metrics.http_req_duration.values['p(95)'] : null,
        }, null, 2) + '\n',
        '/scripts/results/summary.json': JSON.stringify(data, null, 2),
    };
}
