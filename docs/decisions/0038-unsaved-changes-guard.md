# ADR-0038: Unsaved-changes guard on stateful forms

**Status:** accepted

## Context

Admin edit forms (event details/days, venues, settings, …) hold client-side
state in Inertia's `useForm`. Navigating away — a sidebar link, the browser
back button, a tab close — silently discards that state. The event edit form
made the failure visible: users lost day edits and could not tell whether a
save had happened.

One-off dirty checks per page would drift apart in wording, exemption rules,
and cleanup behavior, the same way hand-written `brand_id` clauses drifted
before ADR-0010 made scoping a property of the layer.

## Decision

Every form whose state survives longer than a single interaction (edit forms,
multi-field create forms — not search boxes or filters) gets both of:

- **A visible dirty indicator**: an "Unsaved changes" note rendered next to
  the submit button whenever `form.isDirty`, using the `text-warning` token
  and `role="status"`.
- **A navigation guard** via the shared composable
  `resources/js/composables/useUnsavedChanges.ts`, wired as
  `useUnsavedChanges(() => form.isDirty)`.

The composable is the single implementation; pages must not hand-roll
`router.on('before')` or `beforeunload` listeners. Its contract:

- Inertia **GET** navigations while dirty require a `confirm()`.
- **Non-GET visits are exempt** — the form's own submit and sibling actions
  on the same page must never be blocked.
- **Prefetch and background (async) visits are exempt** — they load data
  without leaving the page.
- Hard exits (reload, tab close, external links) arm the native
  `beforeunload` prompt.
- Listeners are removed on unmount; the dirty state is read through a getter
  at navigation time.

## Consequences

- Users see at a glance whether a form has pending edits, and cannot lose
  them to an accidental navigation.
- New edit forms adopt the pattern with one template block and one composable
  call; behavior and wording stay uniform.
- Browsers do not allow canceling `popstate`, so Inertia back/forward history
  visits cannot be intercepted — the `beforeunload` prompt is the only
  backstop there. This is a platform limit, not a gap to engineer around.
- Existing forms are migrated opportunistically: any PR touching a stateful
  form adds the guard if it is missing.
