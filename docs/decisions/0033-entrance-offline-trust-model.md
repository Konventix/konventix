# ADR-0033: Entrance offline trust model

**Status:** accepted, amended

> **Amended 2026-09-23 by the attendee-list design** (`docs/superpowers/specs/2026-09-23-attendee-list-design.md`, #329).
> A second, online path to a check-in exists: the operator console, which is
> capability-gated and name-aware, and which finds a guest by name when their
> QR code does not work. It writes through the same core as the door,
> `RecordCheckIn`, so both paths share one set of admission checks — day,
> validity, revocation and re-entry — and give the same reason codes; the
> console cannot admit anyone the door would refuse, and the operator answers
> the door checks (ADR-0066) as door staff would.
> It never weakens this ADR's model: the delta feed, the devices and the token
> stay PII-free, the door still sees no names, and the console's own
> checkpoint (`checkpoints.kind = console`, one per event) is never offered to
> a device. Everything below stands unchanged.

## Context

D-COM-06 is the last decision the inventory log carried as explicitly open,
and the inventory's own framing of it stands as written:

> Despite the module map's promise, LanEntrance is **not** offline capable
> today: OCD §5.2 describes a fast local signature pre-check against the
> cached JWKS followed by an unconditional call to the authoritative `POST
> /api/entrance/validate`. If the uplink dies, the door stops. The signed
> token (`TKT-F-017/018`) proves *authenticity* but says nothing about
> *revocation* or *already checked in*, because both live in the database.

Admission's SPEC names the same gap across three open questions: offline
validation model (signature-only versus a downloaded manifest), how much PII
an offline device may hold, and how duplicate/conflicting scans across
devices are resolved. ADR-0002 already names the door as the one legitimate
case for runtime separation from the rest of the platform, and N-3 in the
decision log commits to building genuine offline capability rather than
LanCore's "pre-check, then always call home" shape.

ADR-0018 already constrains part of the answer from the other side: door
payment is a Commerce point-of-sale operation and requires connectivity, so
"the offline client admits but cannot take money" was already true before
this ADR — offline mode narrows what the door can do, it does not narrow
what admission validation needs to work.

The inventory weighed three options directly:

> **Option A — Signature-only trust.** The device verifies the KVX1 signature
> and expiry against the cached JWKS and admits on that alone, buffering
> scans for later reconciliation. No admission list on the device. *Pros:*
> zero PII on the device — a stolen scanner leaks nothing and can forge
> nothing (public keys only); works with any number of admissions; the
> device needs no sync window before doors open; token format needs no
> change. *Cons:* a revoked admission is admitted until its token expires;
> duplicate detection is per-device only, so the same QR photographed and
> shared works at two lanes simultaneously; the operator sees a name only if
> it is in the token, which would mean putting PII in the QR.
>
> **Option B — Manifest download.** The device downloads a manifest of valid
> admissions before the event (holder hash or name, admission ID, validity
> window, revocation flag) and checks membership locally, refreshing deltas
> whenever connectivity returns. *Pros:* revocation works within the refresh
> interval; the operator sees the holder's name and can search offline;
> cross-device duplicate detection is possible within a synced window; the
> door genuinely keeps working. *Cons:* attendee data at rest on a device
> that lives on a folding table at a venue — a GDPR question Admission's SPEC
> already flags and that needs Compliance's sign-off; a large event means a
> real sync step and a stale-manifest failure mode; the manifest needs
> encryption at rest, remote wipe and an expiry-after-event rule.
>
> **Option C — Signature-only by default, with a revocation-delta feed.**
> Option A plus a small, frequently refreshed list of *revoked* admission IDs
> and already-checked-in IDs. Positive decisions come from the signature;
> negative decisions come from the delta. *Pros:* the list is short
> (revocations are rare) and contains no PII — just opaque IDs; closes
> Option A's worst gap; degrades to Option A rather than to "door closed"
> when the feed is stale. *Cons:* the operator still cannot see who is in
> front of them, so the manual name-based fallback that LanCore's search
> endpoint provides is gone offline; cross-device duplicate detection is
> still limited by the refresh interval; two sync mechanisms if a manifest
> is added later anyway.

## Decision

**Option C. Local signature verification — a signed token checked against
cached public keys — grants admission. A small, frequently-synced,
PII-free delta of revoked admission IDs and already-checked-in admission IDs
supplies the negative decisions signature verification cannot make alone.
The device degrades to signature-only when the feed is stale; it never
degrades to a closed door.**

- **Zero PII on devices by default.** The delta feed carries opaque
  admission IDs only — no names, no hashes standing in for names. A lost or
  stolen scanner leaks nothing about attendees and cannot forge an
  admission, because it holds only public keys.
- **Holder-name display is future, opt-in work with its own Compliance
  decision.** This ADR deliberately does not smuggle a manifest in through
  the back door; if operator demand for on-screen names or offline search
  materializes, that is Option B's territory and needs Compliance's sign-off
  on attendee data living on venue hardware, not a quiet extension of the
  delta feed.
- **Re-entry (D-ADM-03): unlimited by default, the event carries the
  policy knob** (once-only / once-per-day / unlimited). Checkpoints stay
  policy-free — a checkpoint enforces whatever the event's policy says, it
  does not carry its own opinion. This is a local decision the signature
  plus the checked-in delta can already make without a network call.
- **Day-scoped validity (D-ADM-04) constrains the token and window design.**
  An event-day is first-class, not a derived slice of a from/until pair, so
  a token's validity is expressed per admission per day (or day-range), and
  the device needs the current event-day resolved locally — from device
  time plus a synced event-day calendar, not a network round trip — to
  evaluate a token offline. Token format and the local evaluation logic are
  both larger than an expiry-only check as a direct result.
- **ADR-0018 still holds, now doubly.** The offline client admits but cannot
  take money: door payment already required connectivity as a Commerce POS
  operation, and offline mode does not add a cash path, it only makes
  explicit that losing the network narrows the door to validation and
  admission alone.

### Alternatives considered (rejected)

**Option A, signature-only with no revocation feed.** Rejected on its own
stated cons: a revoked admission stays valid until its token expires, and
duplicate detection has no cross-device memory at all — the same QR,
photographed and shared, admits at two lanes simultaneously with nothing to
notice. Option C keeps every one of Option A's pros (no PII, no sync window
before doors open, unchanged token trust model) and buys back exactly this
gap.

**Option B, full manifest download.** Rejected: it puts attendee data — even
just a name or a holder hash — at rest on hardware that sits on a folding
table at a venue, which is precisely the GDPR question Admission's SPEC
flags as needing Compliance's sign-off before it can be built at all. It
also requires encryption at rest, remote wipe and an expiry-after-event
rule that Option C needs none of, and a stale-manifest failure mode that
Option C's smaller delta avoids by degrading to signature-only instead.

## Consequences

- The manual, name-based search fallback LanCore's entrance search endpoint
  provided is gone while offline. The operator cannot see who is standing in
  front of them if the scan fails and the network is down — a real
  regression in door-staff experience, accepted deliberately as the cost of
  keeping PII off the device.
- Cross-device duplicate detection is bounded by the delta feed's refresh
  interval, not instantaneous. A shared QR can still double-admit across two
  lanes within one refresh window; the feed shrinks that window, it does not
  close it.
- The delta feed needs a defined sync cadence and staleness threshold before
  build — "frequently-synced" is a property this ADR requires, not a number
  it sets. That is Phase 2 design work sitting on top of this decision.
- Token format grows real complexity: authenticity (existing KVX1 signature
  scheme), validity window, and now day-scoping (D-ADM-04) and re-entry
  policy evaluation all have to be resolvable locally, with no server round
  trip, from the token plus the synced delta plus the device's event-day
  calendar.
- If a manifest is ever added for name display, it arrives as a second,
  independent feed alongside the revocation delta — this ADR accepts two
  sync mechanisms as a future possibility rather than redesigning the delta
  to anticipate it now.
