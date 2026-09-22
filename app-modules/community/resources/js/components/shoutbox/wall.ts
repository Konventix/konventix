/**
 * The shoutbox wall's state as plain data (design §8): the lines the page
 * holds, ordered by id, and the transitions the socket, the reconciliation
 * fetch and the compose box apply to them. Plain functions over plain
 * arrays, so the rules below are readable without Vue and the reactive
 * wrapper (`useShoutboxWall.ts`) only decides *when* they run.
 *
 * Both shapes are the generated ones — the same Data classes the server
 * sends over the socket and over HTTP — so a line that arrived by broadcast
 * and a line that arrived by fetch are the same object.
 */
export type WallLine = Konventix.Community.Data.ShoutboxLineData;
export type WallTombstone = Konventix.Community.Data.ShoutboxTombstoneData;
export type WallEntry = WallLine | WallTombstone;

/**
 * A line the compose box has sent and the server has not answered yet: the
 * optimistic half of design §8. It has no id — the server mints the ULID —
 * so it is keyed locally and always renders after every confirmed line.
 */
export type PendingLine = {
    key: string;
    body: string;
};

/** A removed line: "Von der Orga entfernt", with neither body nor author (spec §4.2). */
export function isTombstone(entry: WallEntry): entry is WallTombstone {
    return 'removedAt' in entry;
}

/**
 * One entry into the wall, by id. ULIDs sort by the instant they were
 * minted, so string order is chronological order and the wall stays sorted
 * without trusting anybody's clock.
 *
 * **A tombstone is final.** A removal replaces its line, but a line never
 * replaces a tombstone: a broadcast, a fetch or the author's own post
 * response can each arrive after the removal did, and none of them may
 * bring the line back.
 */
export function upsert(entries: WallEntry[], incoming: WallEntry): WallEntry[] {
    const index = entries.findIndex((entry) => entry.id === incoming.id);

    if (index !== -1) {
        const current = entries[index];

        if (
            current !== undefined &&
            isTombstone(current) &&
            !isTombstone(incoming)
        ) {
            return entries;
        }

        const next = entries.slice();
        next[index] = incoming;

        return next;
    }

    const next = entries.slice();
    const at = next.findIndex((entry) => entry.id > incoming.id);

    if (at === -1) {
        next.push(incoming);
    } else {
        next.splice(at, 0, incoming);
    }

    return next;
}

export function mergeAll(
    entries: WallEntry[],
    incoming: WallEntry[],
): WallEntry[] {
    return incoming.reduce(upsert, entries);
}

/**
 * A removal for a line this page holds. One the page never loaded — above
 * the oldest line it fetched — is not added: there is nothing on screen to
 * turn into a tombstone.
 */
export function remove(
    entries: WallEntry[],
    tombstone: WallTombstone,
): WallEntry[] {
    return entries.some((entry) => entry.id === tombstone.id)
        ? upsert(entries, tombstone)
        : entries;
}

/** The newest id the page holds — what "everything since" is asked from. */
export function newestId(entries: WallEntry[]): string | null {
    return entries.length === 0
        ? null
        : (entries[entries.length - 1]?.id ?? null);
}

/** The oldest id the page holds — what scrolling upward pages before. */
export function oldestId(entries: WallEntry[]): string | null {
    return entries[0]?.id ?? null;
}
