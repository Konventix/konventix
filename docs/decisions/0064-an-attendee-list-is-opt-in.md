# ADR-0064: An attendee list is opt-in — per person, and per event

**Status:** accepted. Builds on ADR-0051 §7 and ADR-0052.

## Context

Bugfix round 2026-09-21, Task 15: the operator wants "who is coming" on
the public event page — the LAN-party habit of scanning the list for a
familiar handle before deciding to register. Nothing in the codebase
lists people publicly today, and nothing anybody has agreed to is a
consent to be listed: `profile_visibility` (ADR-0052) governs the
person's own profile *page*, and a profile that is `public` says "you may
look me up", not "put me on a roster next to an event I paid for".

ADR-0051 §7 already settled that membership of a brand is personal data
("a non-member gets a 404, not a teaser"). Attendance of one event is at
least that: a registration is a commercial act (ADR-0005) with a billing
party attached, and it names a place and a weekend the person will be
at. A default-on list would publish that fact for every registrant who
never asked.

Registration owns the fact of attendance but may not read Identity's or
Event's models (ADR-0004); Identity owns the person's choice; Event owns
the event's own switch. The list therefore crosses three modules and has
to be assembled from contract events, the way every other read model
here is.

## Decision

1. **Two switches, both default off, both required for a name to show.**
   The person opts in once, operator-wide, with `users.listed_in_attendees`
   ("List me as an attendee on event pages I am registered for") in the
   profile settings. The operator switches the list on per event with
   `events.attendee_list_visibility`: `off` (nothing at all), `members`
   (signed-in accounts on the brand host see names; everyone else sees the
   count), `public` (anyone sees names). A person who has not opted in is
   **counted** wherever the count shows and **never named**.

2. **The count may show under the event's switch alone.** "42 people are
   coming" reveals nothing about any one of them; it is the number the
   operator's landing page wants and the number a visitor decides on. It
   is computed from confirmed registrations only, one per person — a
   person with two registrations for the same event is one attendee.

3. **A name is the username and nothing else.** Never the display name,
   never the e-mail, never the organization or any billing field, and
   never the account's ULID — a public page has no business handing out
   an operator-wide identifier. The username is the handle the person
   chose to be known by (ADR-0051 §3) and the only thing ADR-0051 §7
   already shows the world. Names are ordered by username, so the list
   says nothing about who registered first.

4. **Confirmed registrations only.** `Submitted` and `Approved` are
   promises, not attendance; a cancelled or rejected registration drops
   the person from count and names alike the moment it happens. The
   registration status is the whole rule — payment status is deliberately
   not consulted, because a confirmed free registration is as much an
   attendance as a paid one (ADR-0005's two independent fields).

5. **The profile link exists only where Community does.** The list links a
   name to `/u/{username}` (ADR-0051 §7) when `ModuleGate` says Community
   is active for the brand, and renders plain text otherwise. Registration
   writes the path as a literal because it may not import Community's
   routes or models; the gate is the same one the topbar honours.

6. **Registration assembles the list from contract events, never from
   foreign tables.** `UserProfileUpdated` widens with `listed_in_attendees`
   (operator-wide, like `username` and `visibility`, ADR-0052 §5's
   mechanics: every dispatch site and every hand-built test payload changes
   together, and `identity:replay-profiles` backfills). The registrant read
   model (`registration_registrants`) gains `username` and `listed` from
   it. Event emits `EventAttendeeListPolicyChanged` — the shape of
   `EventReentryPolicyChanged`: the complete current value, at creation and
   on every change — and Registration projects it onto `EventSummary`. The
   landing-page card, the attendees page and the count all read one shared
   query, `Attendees\PublicAttendees`, so no second surface can drift.

7. **The attendees page is Registration's, addressed by the event's slug,
   and answers 404 before it answers "sign in".** `GET
   /events/{slug}/attendees` resolves through Registration's own
   `EventSummary`; an event the landing page would 404 on
   (`Event::isVisibleOnLanding()`'s rule, held here as `public_since` or
   `unlisted`) or whose switch is `off` is a 404, not a login prompt — the
   existence of a hidden event is not for a guest to learn from a redirect.
   Only a `members` list on a visible event asks a guest to sign in.

## Alternatives rejected

- **Reusing `profile_visibility = public` as the consent.** Different
  question, different answer: a caster with a public profile may still not
  want every LAN they attend enumerated. One switch that means two things
  is the "configure and forget" failure ADR-0052 avoided.
- **Default on, opt out.** Publishes a fact about every existing registrant
  the day the feature ships; the GDPR shape ADR-0050/0051 chose is
  disclosure by choice.
- **Display names on the list.** Friendlier, and exactly the field a
  registrant has the least control over being matched against — the
  display name is what appears on the invoice.
- **Registration reading `events.attendee_list_visibility` or
  `users.listed_in_attendees` directly.** Forbidden by ADR-0004, and the
  projection is not expensive: one boolean per registrant, one string per
  event.

## Consequences

- `users.listed_in_attendees` (default false) joins the profile-of-record;
  Identity's export lists it and anonymization resets it — a scrubbed
  account has nothing left to be listed as.
- `events.attendee_list_visibility` (default `off`) joins the event editor
  beside the re-entry policy; a new contract event joins Event's SPEC.
- `registration_registrants` gains `username` and `listed`;
  `registration_event_summaries` gains `attendee_list_visibility`, backfilled
  once from `events` by the migration, the same one-off shape the
  `visibility` column used (Registration reads `events` in migrations only).
- The `event.landing` slot gains a second Registration card (order 15,
  after the offers); the payload of both the card and the page is
  `AttendeeData` — `username` and an optional `profileUrl` — and the tests
  assert that `display_name`, `email`, `organization_name` and the user
  ULID are absent from it.
- `identity:replay-profiles` is re-run after deploy so registrants learn
  their usernames and opt-in state; until then a landing page shows the
  count and no names, which is the safe direction.
