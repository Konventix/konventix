# ADR-0067: Community owns event-scoped rooms, and wears an admission badge it does not enforce

**Status:** accepted (2026-09-23, design at
`docs/superpowers/specs/2026-09-23-shoutbox-design.md`, D-COM-01..09; GitLab #108).
Amends two sentences of `app-modules/community/SPEC.md`.

## Context

Community's SPEC opens by saying the module owns the participant community
that exists "**between** events, not inside one", and later that its presence
signal "**must never be treated as an attendance or access record**". Both
sentences were written before the module had a consumer, and both are good
instincts: the first is what stops Community collapsing into a per-event chat
bolt-on, and the second is what stops an online dot being mistaken for a door
scan.

Release 0.9.0 gives the module its first real surface — the event shoutbox
(#108) — and the shoutbox contradicts both sentences as written. It is a wall
for one event, and the badge beside a name says whether the door has seen that
person today. Rather than let a feature commit quietly reverse two stated
rules, this ADR reverses them deliberately and says what survives.

A third thing needs saying because the word is already taken: **ADR-0054 uses
"presence" to mean an account's presence on a brand** — the membership that
decides what a brand may show or send. That is a different concept from the
online/away/checked-in signal this ADR governs, and the two must not be read
into each other. Where the distinction matters, this ADR says *connection
state* or *admission badge*.

## Decision

1. **Community is brand-scoped and owns event-scoped rooms within it.**
   A room is first-class and carries a subject — an event now, a match or a
   competition later. The cross-event community identity that the SPEC's
   original sentence was protecting — profile, achievements, membership — is
   unchanged and remains brand-scoped. What changes is that a *room* belongs
   to a subject rather than to the brand at large.

   The alternative was a second module for event-day chat. It would have
   duplicated moderation, profiles and the connection signal, and produced two
   places to look for "what was said", which is the outcome the original
   sentence existed to avoid in the first place.

2. **Community projects an admission badge, and never enforces on it.**
   The Checked-In state is projected from Admission's public events
   (`ParticipantAdmitted`, `ParticipantPresenceChanged`) into Community's own
   read model. It is **displayed** — beside a name, in a presence list —
   and nothing in Community grants, refuses or gates anything on it.

   The access gate for the shoutbox is Platform's `ActiveEventContext`, which
   asks Admission's own answer (ADR-0004: through events, never a table read).
   So the SPEC's original prohibition survives in the form that mattered:
   Community still does not *decide* attendance, it only *shows* what
   Admission decided.

3. **Connection state is never persisted.** Online, Away and
   active-in-this-room live in Redis with a TTL and are rebuilt from
   heartbeats. A restart loses them, correctly, because they describe a
   connection that no longer exists. Nothing about *when* a person was at
   their keyboard is written to disk — which is what keeps (2) honest, since
   the only durable half is a badge Admission already holds.

## Consequences

- `app-modules/community/SPEC.md` is amended in two places, each citing this
  ADR: the purpose sentence gains event-scoped rooms, and the presence
  sentence gains the badge exception with its "displayed, never enforced"
  qualifier.
- The room's subject enum ships with `match` and `competition` cases that
  nothing creates yet. They wait on sub-project B; the schema does not.
- A mute is per room. With one room per event that is the same thing; when
  match rooms arrive, a mute that should span them becomes a visible decision
  rather than an accident of the schema.
- Chat retention is Community's own clock (30 days after `EventConcluded`,
  D-COM-05) rather than an inherited one. A shoutbox is ephemeral by nature
  and the shortest defensible clock is the right one; Compliance's
  event-scoped rules stay the floor, not the ceiling.
- The open question Community's SPEC carried about chat transport is closed by
  ADR-0021/ADR-0025 and needs no separate answer here; the TODO line asking
  for an ADR on it was already stale.
- "Presence" now provably means two things in this repository. Both this ADR
  and ADR-0054 say which, and new code should prefer *connection state* or
  *admission badge* over the bare word.
