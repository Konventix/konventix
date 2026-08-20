import type { EventDay, ReentryPolicy } from './evaluate';
import type { SigningKey } from './token';

/**
 * The entrance API client (ADR-0033). Talks to the three endpoints Slice 5a
 * built, authenticating with the device's own bearer token.
 *
 * Every call here is allowed to fail. A door with no uplink is the normal
 * operating condition this whole client exists for, so a rejected fetch marks
 * the state stale and nothing more — it never blocks a scan and never
 * surfaces as an error the operator has to dismiss.
 */

export type DeltaResponse = {
    since: number;
    next: number;
    entries: Array<{ admission_id: string; kind: string }>;
    reentry_policy: ReentryPolicy;
    days: EventDay[];
    poll_seconds: number;
    stale_after_seconds: number;
};

export type ScanSubmission = {
    token: string;
    client_scan_id: string;
    scanned_at: string;
    direction: 'in' | 'out';
};

export type ScanResult = {
    client_scan_id: string;
    result: 'accepted' | 'rejected';
    reason_code: string | null;
    duplicate: boolean;
};

export type SyncResponse = {
    results: ScanResult[];
    accepted: number;
    rejected: number;
    duplicates: number;
};

export class EntranceApi {
    constructor(
        private readonly baseUrl: string,
        private readonly deviceToken: string,
    ) {}

    async signingKeys(): Promise<SigningKey[]> {
        const response = await this.request(
            'GET',
            '/api/entrance/signing-keys',
        );

        return ((await response.json()) as { keys: SigningKey[] }).keys;
    }

    async delta(since: number): Promise<DeltaResponse> {
        const response = await this.request(
            'GET',
            `/api/entrance/delta?since=${since}`,
        );

        return (await response.json()) as DeltaResponse;
    }

    /**
     * Batches are capped to match the server's own `max:500` validation —
     * a device that has been offline for a day must chunk rather than have
     * its whole buffer rejected.
     */
    async submitScans(scans: ScanSubmission[]): Promise<SyncResponse> {
        const response = await this.request('POST', '/api/entrance/scans', {
            scans: scans.slice(0, 500),
        });

        return (await response.json()) as SyncResponse;
    }

    private async request(
        method: string,
        path: string,
        body?: unknown,
    ): Promise<Response> {
        const response = await fetch(`${this.baseUrl}${path}`, {
            method,
            headers: {
                Authorization: `Bearer ${this.deviceToken}`,
                Accept: 'application/json',
                ...(body === undefined
                    ? {}
                    : { 'Content-Type': 'application/json' }),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });

        if (!response.ok) {
            throw new Error(
                `Entrance API ${method} ${path} failed with ${response.status}`,
            );
        }

        return response;
    }
}

/**
 * Whether the device should now consider its delta stale and say so.
 *
 * Staleness does not close the door — it degrades the door to signature-only,
 * which ADR-0033 is explicit about: "it never degrades to a closed door". The
 * only thing that changes is what door staff are told.
 */
export function isStale(
    lastSyncedAt: Date | null,
    staleAfterSeconds: number,
    now: Date,
): boolean {
    if (lastSyncedAt === null) {
        return true;
    }

    return (now.getTime() - lastSyncedAt.getTime()) / 1000 > staleAfterSeconds;
}
