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
- **A deliberate external redirect is exempt** — see below.
- Listeners are removed on unmount; the dirty state is read through a getter
  at navigation time.

### Deliberate external redirects

Some submits *are* meant to leave: connecting a Stripe account posts to a
controller that answers with `Inertia::location($onboardingUrl)`, and leaving
for Stripe's hosted onboarding is the next step of the flow, not an accident.
Inertia turns such a response into a real document navigation
(`window.location.href = …`), which fires `beforeunload` — so the guard used
to ask "Leave this page?" about the very click the operator made, and
answering "Stay" stranded the onboarding.

A page announces such a navigation with `allowNavigation()`, which the
composable both exports and returns. It disarms the native prompt **page-wide**
— all guards on the page, not only the calling one; the settlement console
carries three, and a dirty field in any of them raised the same dialog — and
stays disarmed until Inertia renders a page again.

Server-driven redirects need no page code at all: `Inertia::location()` makes
Inertia fire its `location` event synchronously right before the hard
navigation, and every mounted guard listens for it. `allowNavigation()` is for
a navigation the page performs itself (assigning `window.location`, posting a
plain `<form>` to another origin), called immediately before it.

Nothing else changes: the redirect that never happens — a rejected connect
that comes back with validation errors — re-arms the guard on that render,
with the typed values still in the form.

## Consequences

- Users see at a glance whether a form has pending edits, and cannot lose
  them to an accidental navigation.
- New edit forms adopt the pattern with one template block and one composable
  call; behavior and wording stay uniform.
- A page that leaves on purpose no longer has to hand-roll around the guard,
  and the exemption is stated once instead of per redirect site.
- Browsers do not allow canceling `popstate`, so Inertia back/forward history
  visits cannot be intercepted — the `beforeunload` prompt is the only
  backstop there. This is a platform limit, not a gap to engineer around.
- Existing forms are migrated opportunistically: any PR touching a stateful
  form adds the guard if it is missing.
