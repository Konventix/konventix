/**
 * The door's local state (ADR-0033). Hand-rolled over IndexedDB rather than
 * pulling in a wrapper: three object stores and no queries beyond get/put is
 * not enough surface to justify a dependency on hardware that has to keep
 * working for a weekend without an uplink.
 *
 * What is stored is deliberately **PII-free**, mirroring the feed itself:
 * opaque admission ULIDs, public keys, the event-day calendar, and the
 * device's own buffered scans. A lost scanner leaks nothing about attendees —
 * that is the whole reason ADR-0033 chose Option C over a manifest download.
 */

const DB_NAME = 'konventix-entrance';
const DB_VERSION = 1;

export const STORE_STATE = 'state';
export const STORE_DELTA = 'delta';
export const STORE_SCANS = 'scans';

export type BufferedScan = {
    client_scan_id: string;
    token: string;
    scanned_at: string;
    direction: 'in' | 'out';
    /** What the door decided locally, so a reconciliation mismatch is visible. */
    local_result: 'accepted' | 'rejected';
    local_reason: string | null;
    synced: 0 | 1;
};

function open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (!db.objectStoreNames.contains(STORE_STATE)) {
                db.createObjectStore(STORE_STATE);
            }

            if (!db.objectStoreNames.contains(STORE_DELTA)) {
                // Keyed by admission id; the value carries the kind, so a
                // later `checked_in` for an already-`revoked` admission
                // cannot resurrect it out of order.
                db.createObjectStore(STORE_DELTA, { keyPath: 'admission_id' });
            }

            if (!db.objectStoreNames.contains(STORE_SCANS)) {
                const scans = db.createObjectStore(STORE_SCANS, { keyPath: 'client_scan_id' });
                scans.createIndex('synced', 'synced');
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function tx<T>(store: string, mode: IDBTransactionMode, run: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    return open().then(
        (db) =>
            new Promise<T>((resolve, reject) => {
                const request = run(db.transaction(store, mode).objectStore(store));
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            }),
    );
}

export function putState<T>(key: string, value: T): Promise<IDBValidKey> {
    return tx(STORE_STATE, 'readwrite', (s) => s.put(value, key));
}

export function getState<T>(key: string): Promise<T | undefined> {
    return tx<T | undefined>(STORE_STATE, 'readonly', (s) => s.get(key) as IDBRequest<T | undefined>);
}

export function putDeltaEntry(entry: { admission_id: string; kind: string }): Promise<IDBValidKey> {
    return tx(STORE_DELTA, 'readwrite', (s) => s.put(entry));
}

export function allDeltaEntries(): Promise<Array<{ admission_id: string; kind: string }>> {
    return tx(STORE_DELTA, 'readonly', (s) => s.getAll() as IDBRequest<Array<{ admission_id: string; kind: string }>>);
}

export function bufferScan(scan: BufferedScan): Promise<IDBValidKey> {
    return tx(STORE_SCANS, 'readwrite', (s) => s.put(scan));
}

export function pendingScans(): Promise<BufferedScan[]> {
    return tx(STORE_SCANS, 'readonly', (s) => s.getAll() as IDBRequest<BufferedScan[]>).then((scans) =>
        scans.filter((scan) => scan.synced === 0),
    );
}

/**
 * Marks delivered scans synced rather than deleting them: a device that has
 * uploaded its buffer should still be able to show door staff what it did
 * during the outage.
 */
export async function markSynced(ids: string[]): Promise<void> {
    const db = await open();
    const store = db.transaction(STORE_SCANS, 'readwrite').objectStore(STORE_SCANS);

    for (const id of ids) {
        const existing = await new Promise<BufferedScan | undefined>((resolve, reject) => {
            const request = store.get(id) as IDBRequest<BufferedScan | undefined>;
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });

        if (existing !== undefined) {
            store.put({ ...existing, synced: 1 });
        }
    }
}
