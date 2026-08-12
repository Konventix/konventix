# ADR-0032: Unassigned admissions live in Admission

**Status:** accepted

## Context

Both modules' SPECs flag the same question from opposite sides. Admission's
open question 7: "Whether the placeholder for a not-yet-named group attendee
is created here on confirmation or held in Registration until a person
exists (paired with the same question in Registration's SPEC)." Registration's
open question 5: "For group and B2B registrations the N people are unknown
at submission time. Which module holds the unassigned-admission placeholder —
Registration or Admission — and when the names are collected." ADR-0005's own
worked example — one exhibitor Registration granting five crew Admissions and
ten voucher-code visitor Admissions — assumes an answer without stating one.

Phase 1 already had to ship something to make group registrations work at
all: `IssueAdmissionsOnRegistrationConfirmed` listens for
`RegistrationConfirmed` and calls `IssueAdmissionsForRegistration`, which
creates one `Admission` row per granted admission in the event payload. The
`admissions.holder_user_id` column is nullable specifically for this case —
"an admission may be issued unassigned for group and B2B bookings and claimed
or named later." This ADR is the retroactive decision that Phase 1's shipped
shape is in fact the answer, not a placeholder pending a different one.

## Decision

**Admission holds holder-less placeholders. `IssueAdmissionsOnRegistrationConfirmed`
issues one `Admission` row per granted admission the moment `RegistrationConfirmed`
fires, with `holder_user_id` left null. Names attach later through a claim
link or claim code — a Phase 2 assignment flow not yet built. Registration
stays commercial-only, per ADR-0005.**

- This blesses the existing Phase 1 implementation rather than introducing a
  new one: `IssueAdmissionsForRegistration` already does exactly this, and
  the nullable `holder_user_id` column already exists for it.
- Registration never creates a person-shaped row. It confirms the commercial
  act and emits the grants in `RegistrationConfirmed`'s payload as scalars —
  ADR-0004's contract — and Admission is the only module that turns "N
  admissions of role X" into N actual rows.
- Claiming is a future write against an existing `Admission` row: it sets
  `holder_user_id` (and whatever identity data the claim flow collects), it
  does not create the row. The row, and therefore the admission's existence
  and state, predate the person who will hold it.

### Alternatives considered

**Registration holds pending entitlements; Admission is created only once a
name is known.** Keeps Admission's rows always fully formed — an `Admission`
row would never exist without a holder — and lets Registration answer "how
many people has this booking committed to admit" without crossing the
module boundary. Rejected: a holder-less entitlement is, functionally,
exactly what ADR-0005 defines Admission to be — "one person's access right" —
just before a person is attached to it. Putting that shape in Registration
means Registration grows a second state machine (entitlement →
claimed/unclaimed) parallel to Admission's own issued/checked-in states, and
the claim flow itself — resolving a link or code to exactly one
still-unclaimed slot — would have to live in Registration or reach across
the boundary into Admission at claim time regardless. It also reopens the
line ADR-0005 exists to draw: Registration would be modelling *how many
people* a purchase admits, which is what Admission is for.

## Consequences

- This is low-risk to accept: it validates code already running, it does not
  change behaviour, and Phase 1's group-registration flows keep working
  exactly as shipped.
- The claim flow itself remains entirely unbuilt. Nothing yet validates that
  a claim link resolves to exactly one still-unclaimed `Admission`, nothing
  prevents two people racing the same link or code, and nothing defines what
  a claim link even looks like (a signed URL, a short code, an email-bound
  token). This ADR settles *where* the placeholder lives, not *how* it gets
  claimed — that is real Phase 2 work with its own design questions.
- Every consumer of `Admission` — check-in, badge rendering, the entrance
  client (ADR-0033) — must already handle a null `holder_user_id`
  gracefully, not treat it as a data-integrity violation. Some of that
  handling exists from Phase 1; some (badge rendering for an unclaimed
  admission, entrance-client display) is new surface this ADR does not
  design.
- A large B2B booking issues all N placeholder rows at confirmation, not
  gradually as names arrive. Admission's row count for that registration is
  fixed the moment `RegistrationConfirmed` fires, which is simple and
  matches ADR-0005's model directly, at the cost of no partial-issuance path
  if an organizer wants to add more admissions to an already-confirmed
  booking — that is Registration's still-open "amendment" question
  (Registration SPEC open question 6), unaffected by this ADR either way.
