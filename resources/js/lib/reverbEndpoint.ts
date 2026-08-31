export type ReverbEndpoint = {
    wsHost: string;
    wsPort: number;
    wssPort: number;
    forceTLS: boolean;
    wsPath: string;
};

type PageLocation = { hostname: string; protocol: string; port: string };
type BuildTimeEnv = { host?: string; port?: string; scheme?: string };

/**
 * Where the browser's websocket connects (ADR-0049 §5).
 *
 * Dev builds bake VITE_REVERB_HOST/PORT/SCHEME into the bundle and connect
 * straight to the Reverb container — that path is kept verbatim. The
 * production image is built without them, so the endpoint is derived from
 * the page itself: same hostname, the page's own port and scheme, and the
 * fixed `/ws` path the proxy routes to the Reverb backend
 * (REVERB_SERVER_PATH=/ws server-side). One image, any deployment — and the
 * endpoint can never drift from the brand host the visitor is actually on
 * (ADR-0025).
 */
export function resolveReverbEndpoint(
    location: PageLocation,
    env: BuildTimeEnv,
): ReverbEndpoint {
    if (env.host) {
        const port = Number(env.port ?? 8080);

        return {
            wsHost: env.host,
            wsPort: port,
            wssPort: port,
            forceTLS: env.scheme === 'https',
            wsPath: '',
        };
    }

    const secure = location.protocol === 'https:';
    const pagePort = location.port === '' ? undefined : Number(location.port);

    return {
        wsHost: location.hostname,
        wsPort: secure ? 80 : (pagePort ?? 80),
        wssPort: secure ? (pagePort ?? 443) : 443,
        forceTLS: secure,
        wsPath: '/ws',
    };
}
