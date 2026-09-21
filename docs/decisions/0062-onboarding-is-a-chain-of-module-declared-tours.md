# ADR-0062: Onboarding is a chain of module-declared tours, offered and never forced

**Status:** proposed

## Context

Release 1.0.0 moves every eventula-manager user onto a different application
at once: new shell, new navigation, new registration and shop flows. Nothing
in the product today tells a person what changed or where things went, and
nothing will tell them next release either — a feature that ships without a
pointer to it is discovered by accident or not at all.

Three facts shape the answer. Accounts are global per operator (ADR-0016), so
a person who learned the shop on one brand does not need to learn it again on
the next. Modules and per-brand features decide what a brand shows
(ADR-0003, ADR-0047), so the same person on a second brand may meet a module
the first brand never enabled. And the shell must not know what the modules
are (ADR-0002): a hard-coded "Welcome" script in `resources/js` would either
mention features a brand does not have or be edited by every module in turn.

The onboarding must also stay out of the way. A tour that opens itself on
login is the thing every user closes unread; the value is in being *findable*
by the person who wants it, and in being *right* for that person's brand,
role and history.

## Decision

1. **A tour is a module's declaration, registered at boot.** Platform owns a
   `TourRegistry`; each module declares its `Tour`s in its ServiceProvider
   next to its topics and slot contributions (ADR-0003 registration, the
   `Topic`/`BrandFeature` shape):

   ```php
   new Tour(
       key: 'registration:register-for-event',
       module: 'registration',
       title: 'Registering for an event',
       since: '1.0.0',                 // the release that introduced this tour
       onboarding: true,               // part of the Welcome chain
       audience: TourAudience::User,
       steps: [new TourStep(anchor: 'registration.event-card', title: …, body: …, route: 'events.show'), …],
       supersedes: null,               // key of an older tour this replaces
       feature: null,                  // optional per-brand Pennant feature (ADR-0047)
       order: 30,
   );
   ```

   Steps point at **`data-tour` anchors**, never at class names or DOM
   structure; a step may name the route it lives on, and the engine
   navigates there through Inertia and resumes. A step whose anchor is not
   on the page is skipped, never fatal — a brand's theme or a hidden slot may
   legitimately leave it out.

2. **"Welcome to Konventix" is composed, not authored.** There is no Welcome
   tour in any module. The registry assembles it at request time from every
   tour flagged `onboarding: true` that passes the brand's gates (module
   active, feature on, capability held) and that the user has not completed,
   in `order`. A module a brand does not have contributes nothing there; when
   the same person signs in on a brand that has it, its tours are the only
   ones left, and the Welcome offer is exactly those. `onboarding: false`
   tours are reachable from a page's own entry point and from "What's new",
   never from the chain.

3. **A change supersedes; it does not append.** A tour whose feature or UX
   changed materially is redeclared under a **new key** with `supersedes:
   '<old key>'` and its own `since`. The old key stays declared until every
   supported release has passed it, so a stored completion still resolves.
   For a user who completed the old tour the new one is unseen — it appears
   as "changed in 1.1" — and completing it marks both. A tour that merely
   fixes wording keeps its key. There is no per-release "has read the
   release notes" flag: **unseen is the only signal**, and `since` groups it.

4. **Completion state is per user, operator-wide, typed.** `users.preferences`
   gains `onboarding: OnboardingStateData` (ADR-0009): `completed: list<string>`
   of tour keys, `inProgress: ?array{tour: string, step: int}` so a chain
   resumes where it was left, `dismissedWelcomeAt`, `optedOut: bool`. It is
   deliberately **not** on `brand_memberships.preferences`: the person learned
   the feature, not the brand (ADR-0016). Progress is written per step over
   one small endpoint, so a tour closed halfway is halfway on the next
   device. The column already rides Identity's GDPR export.

5. **Pull, not push.** Nothing auto-starts. The surfaces are:
   - a **"What's new" entry** in the account menu (`accountNav`, one nav
     registration in Platform) with a dot while unseen tours exist, opening
     a sheet that lists tours grouped by `since`, each with *Start* and
     *Mark as seen*;
   - a **page-level entry** (`?` in the topbar) that offers the tours whose
     steps live on the current page;
   - **one Messaging notification per release**, topic
     `platform:whats-new` (in-app default, digestable, suppressible —
     ADR-0041), sent by `onboarding:announce` from the deploy pipeline
     (ADR-0049) to every user who has an unseen tour with `since` equal to
     the running `app.version`. A user who does not want it turns the topic
     off in the preferences they already have;
   - a **single Welcome offer** on the first authenticated page of an
     account with no completed tour and at least one onboarding tour open —
     an inline card, one click to start and one to dismiss, never shown
     again once `dismissedWelcomeAt` is set. This is what a migrated
     eventula-manager account meets on first login; the import seeds an
     empty `onboarding` state and needs no special path.

6. **The engine is ours, on reka-ui.** `useTour()` in `resources/js/composables`
   renders a step as a reka-ui `Popover` anchored to the `data-tour` element
   with an SVG cut-out mask over the page. No tour library: theming stays
   semantic (ADR-0008), and ADR-0055 is met by construction — focus moves
   into the step, `Escape` ends the tour, arrow keys and buttons step,
   the step count is announced (`role="dialog"`, `aria-live` on the
   progress), `prefers-reduced-motion` drops the mask transition. Tour text
   is module content and follows the module's locale handling (#132).

7. **Users first; operators later on the same rails.** `TourAudience` has
   `User` and `Operator` from the start and the registry filters on it, but
   only the brand shell (`AppTopbarLayout`) mounts the engine and the
   surfaces in point 5. Mounting them in `AdminLayout` and declaring
   `Operator` tours is additive: same registry, same state column (the
   account is global), same endpoint. Nothing in this ADR is undone by it.

### Alternatives rejected

**A tour library (driver.js, shepherd).** A dependency for a popover and a
mask, with its own theme tokens to override and an accessibility surface to
audit rather than own. Neither knows about modules; the composition in
point 2 would sit on top of it anyway.

**A hand-written Welcome script in the frontend.** Cannot be right for a
brand with the shop off or Competition disabled, and the shell would carry
knowledge of every module (ADR-0002). Composition from declarations is the
same move `SlotRegistry` and `NavigationRegistry` already make.

**State on `brand_memberships`.** Would replay the registration tour on
every brand the person joins, for no gain: the second brand's differences
are already captured by which tours remain unseen there.

**A "last acknowledged version" per user instead of per-tour completion.**
One integer, no list — but it cannot express "saw the shop tour, never saw
the seating one", nor resume, nor a superseded tour, and a brand that turns
a module on mid-release would show nothing new to anyone.

**Laravel's notification system for the release note.** ADR-0041 already
decided Messaging carries every in-app and mail delivery; a second path
would bypass topics, preferences and suppression.

## Consequences

- Platform gains `Tours/{Tour,TourStep,TourAudience,TourRegistry}`, a
  `platform:whats-new` topic, an `onboarding:announce` command, an
  `accountNav` entry, and one `PUT /account/onboarding` endpoint; Identity
  gains `OnboardingStateData` on `AccountPreferencesData`. No new table.
- Every module that wants to be in the Welcome chain owns its tour
  declarations and its `data-tour` anchors; a module without them is simply
  absent from onboarding, never broken. `ArchitectureTest` gets a check that
  a declared anchor exists in the module's own `resources/js`.
- Deploy runs `onboarding:announce` after migrations; a release with no
  tour carrying its version sends nothing.
- Release notes for users become a by-product of declaring tours: the "What's
  new" sheet *is* the changelog a person sees, filtered to their brand and
  role.
- The 1.0.0 cutover ships with every module that has a user-facing flow
  declaring at least its `onboarding: true` tour; the Welcome chain for a
  migrated account is the union of those on the brand they log into.
- The engine, the offer card and the sheet are tested with the accessibility
  check from ADR-0055 point 6 like any other consumer surface.
