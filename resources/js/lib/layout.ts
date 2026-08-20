/**
 * The opt-out this application's layout resolver understands.
 *
 * `resources/js/app.ts` treats an explicit `layout: null` as "this page has no
 * layout" and only falls back to the name-based default when a page says
 * nothing — that distinction is why `null` and `undefined` cannot be the same
 * value here. Inertia's published type for `layout` does not include `null`,
 * so every public page that opted out did it with a type error.
 *
 * One documented cast in one place, rather than the same cast repeated in
 * every page that needs it. Use it as `defineOptions({ layout: noLayout })`.
 */
export const noLayout = null as unknown as undefined;
