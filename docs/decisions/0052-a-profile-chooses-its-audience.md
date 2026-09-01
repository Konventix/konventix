# ADR-0052: A profile chooses its audience — visibility

**Status:** accepted

## Context

ADR-0051 §7 gave every account a public page, `u/{username}` on the brand
host, and settled its default audience: signed-in members of the brand only,
with a 404 (not a teaser) for anyone else — "whether a person is a member of
a brand is itself personal data." That default was never meant to be the
only answer. Added mid-run at the user's request: a person may want their
page open to the world (a caster promoting a handle, a staffer linking it
from a badge), or narrower still — visible only to people who share an event
with them, the LAN-badge instinct that a stranger three brands away has no
business seeing your tagline.

Registration already knows who is confirmed for what, but Community may not
read Registration's models (ADR-0004) — only its events. Whatever "share a
confirmed event registration" means for visibility has to be answered from a
read model Community projects for itself, the same shape as
`community_profiles` and `community_members`.

## Decision

1. **`profile_visibility` joins the profile-of-record**, `users` column,
   string, default `internal`. Three values: `internal` (ADR-0051's
   members-only rule, unchanged — the default nobody has to think about),
   `public` (the owner's explicit opt-in publishes the page to anyone,
   including guests with no account at all — the 404 for non-members is
   ADR-0051's default, not a prohibition on self-disclosure a person is free
   to waive for their own page), and `event` (the viewer must share at least
   one CONFIRMED event registration with the owner, on the brand the page is
   being viewed on).

2. **Comments stay internal-scope under every visibility.** A `public` or
   `event` page is a wider audience for the profile fields themselves; it is
   not an invitation for anonymous or cross-event comment traffic on slice
   22's generic reaction/comment subject. Only signed-in brand members may
   comment, exactly as ADR-0051 already had it, regardless of what
   `profile_visibility` the owner chose.

3. **`event` visibility is sourced from a Community read model, not
   Registration's tables.** `community_event_participations` projects
   Registration's four relevant contract events —
   `RegistrationSubmitted`, `RegistrationConfirmed`, `RegistrationCancelled`,
   `RegistrationRejected` — keyed by the registration's own ULID (not
   generated: it names the fact Community is tracking, the same way
   `community_profiles`' PK names the account it describes). A row starts
   unconfirmed on submission and is flipped to confirmed by
   `RegistrationConfirmed`; cancellation or rejection removes it outright —
   there is no "used to be confirmed" state worth keeping for a visibility
   check. "Shares a confirmed event registration" then reduces to: both
   users have a `community_event_participations` row with `confirmed = true`
   for the same `event_id` on the brand in question.

4. **Participation is projected going forward only.** Registration has no
   replay command yet (unlike Identity's `identity:replay-profiles`), so a
   brand that activates Community after registrations have already closed
   for an event starts with empty participation for it — nobody's `event`
   visibility resolves against history the projection never saw. This is the
   same shape ADR-0051 already accepted for late activation of
   `community_members` before a replay exists; it is noted here rather than
   solved, because building replay machinery for an event system that has
   never needed it yet would be speculative.

5. **Widened contract, same pattern as ADR-0051 §6.** `UserProfileUpdated`
   gains a required `visibility` field carrying the enum's scalar value —
   operator-wide, because `profile_visibility` lives on `User` like
   `username` and `tagline` do, not on the brand membership. Every dispatch
   site changes together (identity's own actions, the admin edit path, the
   replay command); every payload carries the account's *current*
   visibility, so a replay converges the same way every other field on this
   event already does.

## Alternatives rejected

- **Per-field visibility**, echoing ADR-0051 §7's own rejected idea taken
  further: a visibility knob per profile field instead of one for the whole
  page. Rejected there for the same reason it is rejected here — a second
  system to configure and forget, for a granularity nobody asked for.
- **`event` visibility resolved live against Registration**, via a
  synchronous cross-module call at page-render time. Forbidden outright by
  ADR-0004: Community does not read Registration's models, full stop, and a
  live cross-module read would also mean the profile page's response time
  depends on Registration's query plan.
- **A `Registration` replay command, built now, so late activation is never
  incomplete.** Real machinery for a gap that item 4 already accepts as a
  documented limitation; Identity's replay command exists because profiles
  needed to survive account creation predating the projection, a problem
  registration participation does not yet have evidence of.

## Consequences

- `users.profile_visibility` ships with a migration, `default('internal')`
  — no backfill question, because `internal` is the value every existing row
  already behaves as under ADR-0051 §7.
- `UserProfileUpdated` widens again; the grep sweep touches every
  construction site and every test that builds one by hand, same mechanics
  as ADR-0051 §6's own widening.
- `community_event_participations` is Community's third read model,
  brand-owned like `community_members`, written the same forceFill way for
  the same reason: `brand_id` must never be mass-assignable, and this
  listener runs off any host with no ambient brand in context.
- The settings form gains a visibility choice; anonymization resets it to
  `internal` alongside the rest of the profile-of-record tombstone, because
  a scrubbed account has no audience left to choose a page for.
