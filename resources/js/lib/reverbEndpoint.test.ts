import { describe, expect, it } from 'vitest';
import { resolveReverbEndpoint } from '@/lib/reverbEndpoint';

describe('resolveReverbEndpoint', () => {
    it('keeps the build-time endpoint when VITE values exist (dev)', () => {
        const endpoint = resolveReverbEndpoint(
            { hostname: 'rush.localhost', protocol: 'http:', port: '80' },
            { host: 'localhost', port: '8080', scheme: 'http' },
        );

        expect(endpoint).toEqual({
            wsHost: 'localhost',
            wsPort: 8080,
            wssPort: 8080,
            forceTLS: false,
            wsPath: '',
        });
    });

    it('derives host, TLS and the /ws path from the page when unconfigured (production image)', () => {
        const endpoint = resolveReverbEndpoint(
            { hostname: 'con.acme.example', protocol: 'https:', port: '' },
            {},
        );

        expect(endpoint).toEqual({
            wsHost: 'con.acme.example',
            wsPort: 80,
            wssPort: 443,
            forceTLS: true,
            wsPath: '/ws',
        });
    });

    it('keeps an explicit page port (a proxy on a non-standard port)', () => {
        const endpoint = resolveReverbEndpoint(
            { hostname: 'staging.example', protocol: 'https:', port: '8443' },
            {},
        );

        expect(endpoint.wssPort).toBe(8443);
        expect(endpoint.forceTLS).toBe(true);
    });
});
