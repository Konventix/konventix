/**
 * The entry editor's publish-moment handling, kept out of the page so it can
 * be tested as what it is: one pure function.
 *
 * A `datetime-local` control speaks wall clock and nothing else — it hands
 * back `YYYY-MM-DDTHH:MM` with no zone attached. The server used to read that
 * string in `config('app.timezone')` (UTC), so an editor in Berlin who typed
 * 22:00 scheduled the entry for midnight of the next day — two hours late in
 * summer (#246, the same fault as #237 for a sales window and #202 for a
 * policy version's effective date). The browser is the only party that knows
 * whose clock it was, so the resolution happens here, on the way out.
 *
 * Reading a stored moment back *in* is the shared `formatDateTime()` from
 * `@/lib/datetime`; the field itself starts empty, because "publish at" is an
 * instruction for this click and not a stored value the form edits.
 */
import { toInstant } from '@/lib/datetime';

/**
 * The typed wall clock as the absolute instant the server stores, or `null`
 * for an empty field — which the lifecycle endpoint reads as "now".
 */
export function publishedAtInstant(wallClock: string): string | null {
    return toInstant(wallClock);
}
