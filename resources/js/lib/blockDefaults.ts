/**
 * A fresh, detached payload for a newly added block.
 *
 * `defaultData` reaches the composer as an Inertia page prop, wrapped in a
 * Vue reactive proxy — and `structuredClone` refuses proxies with a
 * `DataCloneError`. A JSON round trip is the right tool here anyway: the
 * defaults come from PHP as JSON, so they are JSON-safe by construction, and
 * the copy is plain data the form can own.
 */
export function freshBlockData(
    defaultData: Record<string, unknown>,
): Record<string, unknown> {
    return JSON.parse(JSON.stringify(defaultData)) as Record<string, unknown>;
}
