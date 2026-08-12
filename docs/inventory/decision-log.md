# Decision log — spec-out session

Running record of decisions taken in the spec-out session against
`docs/inventory/*.md`. Each entry names the option chosen. Where a decision is
architectural it graduates to an ADR and this log points at it; where it is a
modelling detail it graduates to the owning module's `SPEC.md`.

**Status legend:** `decided` — chosen, not yet written up · `adr` — written up as
an ADR · `spec` — written into the module SPEC · `open` — not yet asked.

## Policy engine

| ID | Decision | Chosen | Status |
|---|---|---|---|
| PE-1 | Authorization mechanism | **Authorizing Actions for writes, Query objects for reads.** If it changes state it is an Action; if it only reads it is a Query. | adr → ADR-0015 |
| PE-2 | UI affordances | Per-page explicit `can` props. No global capability map in shared props (lessons #5). | adr → ADR-0015 |
| PE-3 | Cross-brand response | 404 by default; 403 only for actors holding an operator-level cross-brand capability. | adr → ADR-0015 |
| PE-4 | Denial audit | Denied Actions are audited by Compliance; denied Queries are not. | adr → ADR-0015 |
| PE-5 | Transaction ownership | `DB::transaction` stays inside the Action. The dispatcher does not own transactions. | adr → ADR-0015 |
| PE-6 | Privileged execution | Explicit `SystemActor`, passed at the call site. Nullable actor is not permitted. | adr → ADR-0015 |

## Round 1 — foundational

| ID | Decision | Chosen | Status |
|---|---|---|---|
| D-IDP-01 | Account scope | **Global account per operator + per-brand membership rows**, with brand-filtered admin views from day one. Driven by Art. 17 erasure being owed operator-wide. | adr → ADR-0016 |
| D-COM-01 | Price ownership | **Registration declares, Commerce calculates.** `RegistrationType` carries a price declaration value object; Commerce computes totals, tax and rounding and snapshots onto the order. | adr → ADR-0017 |
| D-COM-08 | Door payment | **Door is a Commerce POS.** Separate, separately authorized POS screen; payment → `OrderPaid` → confirmation → admission → scan. No combined endpoint. | adr → ADR-0018 |
| D-CMP-01 | LanBrackets | **Absorb into Competition.** Supersedes ADR-0012's "remains external, integrated via webhooks". | adr → ADR-0019 |

### Consequences already known

- **D-IDP-01** — email uniqueness becomes operator-global, so every "email already
  taken" path must be made indistinguishable from success ("check your inbox",
  never a validation error). Cascades into D-IDP-02, D-IDP-07, D-IDP-12.
- **D-COM-01** — reverse charge needs the billing party's VAT ID, which
  Registration does not have when a type is configured; the treatment is applied
  in Commerce. Repricing history has no home and is deferred. Gates D-COM-10,
  D-COM-12.
- **D-COM-08** — the operator may briefly see "not yet valid" after taking cash;
  this needs deliberate UX handling. Door payment cannot work in offline mode,
  which constrains D-COM-06.
- **D-CMP-01** — ADR-0012 must be amended, not just supplemented. Deletes
  COMP-F-018 and COMP-F-020, and requirement COMP-F-017 (manual webhook replay)
  disappears with the webhooks.

## Round 2 — Resources model and realtime

| ID | Decision | Chosen | Status |
|---|---|---|---|
| D-EVT-01 | Booking time dimension | **Half-open interval `[starts_at, ends_at)` on every booking.** A whole-event booking is a degenerate interval, not a special case. `booking_granularity` on `ResourceType` is a UI hint only. | adr → ADR-0020 |
| D-EVT-02 | No-overlap invariant | **PostgreSQL exclusion constraint**, and the test suite moves off in-memory SQLite onto PostgreSQL. | adr → ADR-0020 |
| D-EVT-11 | Do Agenda and Staffing book? | **No. Plain references.** Agenda items and shifts store a location reference; they do not create `ResourceBooking` rows. | adr → ADR-0020 |
| D-CMM-08 | Realtime transport | **Reverb per brand domain.** No cross-origin problem; websocket isolation is structural rather than enforced in channel authorization. *Revised: one shared Reverb server, one app entry per brand — isolation via per-app credentials; brand domains proxied by Caddy.* | adr → ADR-0021, revised by ADR-0025 |

### Consequences already known

- **D-EVT-02** is a project-wide change: CI needs a PostgreSQL service, tests get
  slower, and the divergence between test and production storage disappears.
- **D-EVT-11 narrows what D-EVT-02 protects.** With Agenda and Staffing holding
  plain references, the exclusion constraint guarantees no double-booking *of
  bookable resources* — seats, booths, devices — but a talk and a shift can still
  claim the same room with nothing noticing. Resources owns contention only for
  things that are booked. See the note below.
- **D-CMM-08** makes operational surface scale with brand count, which is the cost
  curve ADR-0002 exists to avoid. Acceptable while brands are few; revisit if a
  brand becomes cheap enough to create casually.

## Round 3 — satellites, content shape, competition scope

| ID | Decision | Chosen | Status |
|---|---|---|---|
| D-EXT-01 | LanHelp's ticket system | **A new `Support` module, post-release (Phase 3).** Owns SupportRequest, replies, assignment. LanHelp's knowledge base becomes a Content type. | adr → ADR-0022 |
| D-EXT-02 | LanShout | **Absorb into Community**; Reverb becomes a deployment role of the one image. LanShout is deleted, not maintained. | adr → ADR-0021 |
| D-CNT-01 | Content model shape | **Core `content_entries` table plus a 1:1 side table per type.** Shared lifecycle in one place, type columns properly typed. | adr → ADR-0023 |
| D-CMP-03 | Competition scope | **Introduce a `Discipline` abstraction now.** A video game is one kind of discipline; a pinball table, a quiz format or a judged contest are others. | adr → ADR-0024 |

### Consequences already known

- **D-EXT-01 raises the module count from 18 to 19.** Support is Pennant-gated and
  Phase 3. The architecture test asserting a provider count must move with it.
- **D-EXT-02 combined with D-CMM-08** (Reverb per brand domain) means the Reverb
  role is instantiated per brand, so deployment topology grows with brand count.
  This is the ADR-0002 cost curve reappearing in the infrastructure layer rather
  than the application layer; worth watching rather than worth reversing now.
- **D-CNT-01** means adding a content type is a migration, not a data row. That is
  the accepted price for keeping type columns non-nullable and constrained.
- **D-CMP-03** gives GameOps a relationship to design: `Discipline` is Competition's,
  and GameOps handles only the disciplines that are video games. This enlarges
  Phase 2 for Competition, which has also just absorbed LanBrackets.

## Note: an interaction worth revisiting

**Now captured inside ADR-0020**, which decides D-EVT-01, D-EVT-02 and D-EVT-11
as one ADR for exactly this reason and states the interaction in its
Consequences. Kept here as the record of where it was first noticed.

D-EVT-02 and D-EVT-11 were decided together and pull against each other. The
exclusion constraint is bought at project-wide cost (Postgres in CI) to make
double-booking structurally impossible; D-EVT-11 then keeps the two modules most
likely to contend for rooms outside the booking system entirely. The constraint
still earns its cost for seats and booths, which is where the money is. But
"which room is free" becomes a question Resources cannot answer, and room
conflicts between programme and crew are undetected by design. If room contention
turns out to matter, revisiting D-EVT-11 is cheaper than revisiting D-EVT-02.

## Round 6 — the Phase 2 gate review (full-tier interview)

| ID | Decision | Chosen | Status |
|---|---|---|---|
| D-COM-13 | German VAT scope at release | **Standard + reduced rates, reverse charge for B2B exhibitors with VAT ID, §19 UStG as operator flag. OSS out of release scope.** | adr → ADR-0030 |
| D-COM-14 | Invoice documents | **GoBD-compliant immutable PDF + archival, issued as hybrid ZUGFeRD. XRechnung deferred.** | adr → ADR-0031 |
| D-COM-02 | Invitation tokens vs vouchers | **Registration owns invitation tokens (access), Commerce owns vouchers (price). Exhibitor visitor codes are invitation tokens granting a free type.** | spec (Registration, Commerce) |
| D-COM-09 | Unassigned group admissions | **Admission holds holder-less placeholders, issued on confirmation (blesses the Phase 1 implementation); names attach later via claim links.** | adr → ADR-0032 |
| D-COM-06 | Entrance offline trust | **Option C: signature verification + PII-free revocation/checked-in delta feed. Zero PII on devices by default; holder-name display is a separate opt-in feature with its own Compliance decision later.** | adr → ADR-0033 |
| D-ADM-03 | Re-entry policy | **Unlimited by default; the event carries the policy knob (once-only / once-per-day / unlimited). Checkpoints stay policy-free.** | spec (Admission, Event) |
| D-ADM-04 | Day-scoped admissions | **First-class at release: an event-day concept with per-day admission validity, not just a from/until pair.** (User override.) | spec (Admission, Event) |
| D-EVT-03 | Resource capacity model | **Per resource type: unit mode (row per unit, exclusion constraint direct) or quantity mode (capacity column, summed overlap check).** | adr → ADR-0034 |
| D-EVT-13 | Resource catalogue scope | **Per event only — no venue-level catalogue at release.** (User override of recommendation.) | spec (Resources) |
| D-REG-04 | Waiting lists | **Registration-owned waitlist ships at release: quota exhaustion produces a waitlist entry. Resources has no queue and points at Registration's.** (User override.) | spec (Registration, Resources) |
| D-STF-01 | Staffing entry flow | **Self-service sign-up with per-team approval opt-in; an accepted assignment auto-creates the crew Admission via event contract (Staffing emits, Admission listens).** | spec (Staffing, Admission) |
| D-CMP-11 | Result authority | **Handler results record directly and advance the bracket; the desk holds a correction capability.** | spec (Competition) |
| D-CMP-12 | Result corrections | **Distinct `MatchResultCorrected` event; `MatchFinished` fires exactly once per match.** | spec (Competition, GameOps) |
| D-CMP-13 | Solo entrants | **A team of size one; the roster UI hides itself for size-1 teams.** | spec (Competition) |
| D-CMP-05 | Competition entry gate | **Confirmed Registration suffices to enter; admission possession is verified at check-in / match start.** | spec (Competition) |
| D-GOP-01 | Pool reuse | **Configurable per server pool** (user decision); fresh-per-match is the default mode, reuse mode requires a MatchHandler reset hook. | adr → ADR-0012 (updated) |
| D-GOP-02 | Provisioning fallback | **Bounded retries with backoff, then hand to the tournament desk via event. No static-pool rescue tier.** | adr → ADR-0012 (updated) |
| D-GOP-03 | Session liveness | **Provider status + handler heartbeats; loss past threshold emits `SessionUnhealthy` for the desk; never automatic mid-match teardown.** | adr → ADR-0012 (updated) |
| D-GOP-04 | Game catalogue | **GameOps owns games/modes/server requirements; Competition's Discipline references games by ULID and keeps a read model from GameOps events — carrying the fields ADR-0024 documented Competition actually consumes (avg match/stage minutes, signup rules, team size), not just name/icon.** | adr → ADR-0012 (updated), spec |
| D-CNT-05 | Media library | **Platform-owned, brand-scoped media library; modules reference media by ULID.** | adr → ADR-0035 |
| D-CNT-06 | Content localization | **Single locale per brand at release; a translation layer is a post-release platform decision.** | spec (Content) |
| D-CNT-07 | Audience restriction | **Entries are public or login-required at release; participant-gating deferred (would couple Content to Registration state).** | spec (Content) |
| D-MSG-02 | Web push | **Ships at release** (user override): a push MessageChannel driver joins mail + in-app. | spec (Messaging) |
| D-MSG-03 | Digests/batching | **Messaging owns digest windows and quiet hours as infrastructure** (user override); modules send per-change, Messaging coalesces. | spec (Messaging) |
| D-EVT-14 | Event editions | **`predecessor_event_id` ULID reference from the start** (user override of naming-convention default). | spec (Event) |
| D-EVT-15 | Event cardinality | **One brand per event; MULTI-venue at release** (user override): an event carries a venue list with a primary. | spec (Event) |
| D-BATCH | Accepted defaults | **Multi-currency fixed per brand · instalments not at release · bank reconciliation manual · amendment/transfer decided during the paid-flow build · deletion default anonymize with tombstone rows · Compliance scheduler watches DSR deadlines via Messaging · subject requests span the operator · audit = wildcard subscription + explicit recorder for eventless actions · role-assignment screen owned by Platform (users referenced by ULID) · per-event Pennant scoping deferred.** | spec (various) |

### Consequences already known

- **D-ADM-04, D-EVT-15, D-MSG-02, D-MSG-03, D-REG-04** all ADD release scope
  (day concept, multi-venue, push driver, digest infrastructure, waitlists) —
  the definition of done grows by five features relative to the recommended
  minimums, chosen deliberately.
- **D-COM-06 closes** — the last decision the log carried as explicitly open.

### ID corrections

Several IDs assigned during the interview collided with different topics
already numbered in `docs/inventory/*.md` (or, for D-CMP-01, with Round 1's
own use of that ID for the LanBrackets absorption decision). Renumbered to
keep one ID per topic:

| Interview ID | Topic | Corrected to | Reason |
|---|---|---|---|
| D-COM-02 | German VAT scope at release | **D-COM-13** | Collided with inventory's D-COM-02 ("Are invitation codes vouchers, or a separate concept?") |
| D-COM-03 | Invoice documents | **D-COM-14** | Collided with inventory's D-COM-03 ("What is a quota attached to?") |
| D-REG-02 | Invitation tokens vs vouchers | **D-COM-02** | This *is* inventory's D-COM-02; no D-REG series exists in the inventory |
| D-REG-05 | Unassigned group admissions | **D-COM-09** | This *is* inventory's D-COM-09 ("Who holds an admission that has no person yet?") |
| D-EVT-04 | Resource catalogue scope | **D-EVT-13** | Collided with inventory's D-EVT-04 ("Is Venue an Event sub-concept or its own entity?") |
| D-EVT-05 | Event editions | **D-EVT-14** | Collided with inventory's D-EVT-05 ("Where does 'event capacity' live?") |
| D-EVT-06 | Event cardinality | **D-EVT-15** | Collided with inventory's D-EVT-06 ("Who picks the concrete resource, and when?") |
| D-CMP-01 | Result authority | **D-CMP-11** | Collided with inventory's (and this log's own Round 1) D-CMP-01 — LanBrackets absorption, ADR-0019 |
| D-CMP-02 | Result corrections | **D-CMP-12** | Collided with inventory's D-CMP-02 ("Does Competition own a local `Match` row, or does match state stay remote?") |
| D-CMP-04 | Solo entrants | **D-CMP-13** | Collided with inventory's D-CMP-04 ("Is the game catalogue GameOps-only, or shared?") |
| D-CNT-02 | Media library | **D-CNT-05** | Collided with inventory's D-CNT-02 ("Do comments live in Content or in Community?") |
| D-CNT-03 | Content localization | **D-CNT-06** | Collided with inventory's D-CNT-03 ("Are announcements content, or are they messages?") |
| D-CNT-04 | Audience restriction | **D-CNT-07** | Collided with inventory's D-CNT-04 ("Where does 'I have seen this announcement' live?") |

Not renumbered: D-REG-04 (waiting lists), D-ADM-03, D-ADM-04, D-STF-01,
D-GOP-01 through D-GOP-04, D-MSG-02, D-MSG-03 — none of these strings are
used anywhere in `docs/inventory/*.md`, so there is no collision, even though
some (D-STF-01, D-GOP-*) cover ground the inventory raised under a different
module's ID. D-COM-06, D-CMP-03, D-CMP-05, D-EVT-03 already matched the
inventory's numbering for the same topic and needed no change.

## Round 5 — Phase 1 finish and kernel start

| ID | Decision | Chosen | Status |
|---|---|---|---|
| D-COM-12 | Zero-value orders | **`settlement: free` creates no Commerce record at all.** Commerce participates only when money moves; free-registration reporting is Registration's. | adr → ADR-0026 |
| D-REG-08 | Guest registration in Phase 1 | **Registrants must hold an account.** Guest registration is deferred; it requires a non-account subject identity (Compliance) and its own ADR when it comes. | spec (Registration, Identity) |
| D-EVT-12 | Event time storage | **Wall-clock local time plus IANA timezone as data.** UTC would silently shift promised local times when offset rules change. System timestamps stay UTC. | adr → ADR-0027 |
| D-MSG-01 | Mail transport topology | **Per-brand transports from day one**, credentials as encrypted brand data, fallback to the operator/default mailer when unconfigured. DMARC alignment per brand domain by construction. | adr → ADR-0028 |

### Consequences already known

- **D-REG-08** keeps the Identity SPEC's guest-subject question open but
  removes it from the Phase 1 critical path. The account-scope question next to
  it was already settled by ADR-0016 — the SPEC is updated to say so.
- **D-MSG-01** moves credential encryption, per-brand suppression and
  per-brand bounce webhooks into Messaging's release scope.

## Round 4 — naming and the one-application rule

| ID | Decision | Chosen | Status |
|---|---|---|---|
| N-1 | Predecessor naming | **"Konventix" for everything forward-looking; "LanCore" survives only as the proper name of the historical codebase** at `/home/mkohn/git/lan-software/LanCore`. Never use it for the system being built. | spec |
| N-2 | Separate applications | **There are none.** LanBrackets → Competition (ADR-0019), LanShout → Community (ADR-0021), LanHelp → retired, replaced by the Support module (ADR-0022). No future feature gets its own deployment. | spec |
| N-3 | Offline entrance client | **ADR-0002's carve-out stands, and gets built properly.** A genuinely offline-capable client — signed tokens, local validation, reconciliation on reconnect — because a door that stops when the uplink drops ruins an event. | decided |

### Consequences already known

- **N-1** cost the least of the three options but leaves the retired name in the
  documentation. Accepted deliberately: it is the accurate name of a real
  codebase, and the alternative made ~450 comparison rows read as false claims
  about the current system.
- **N-3 conflicts with ADR-0018.** Door payment is a Commerce POS operation and
  needs connectivity; offline admission validation does not. Both are true, so
  the offline client validates and admits but cannot take money. This constrained
  **D-COM-06**, since closed in Round 6 (ADR-0033: the offline client validates
  and admits but cannot take money).
- **N-3 is new work, not a port.** The predecessor's entrance client was never
  offline: it did a local signature pre-check and then always called the
  authoritative endpoint, so the door stopped when the uplink did.
- Module count is now **19**; `module-map.md` carries Support and an explicit
  "the satellites are gone" section.

## Open

**56 of the 68 inventory decisions remain open.** They are written up in the
inventory documents with context, options, honest costs and a recommendation:

| Area | Open |
|---|---|
| Identity / Compliance / Platform | 12 |
| Commerce / Registration / Admission | 10 |
| Content / Messaging / Community | 10 |
| Event / Resources / Agenda / Staffing | 9 |
| Competition / GameOps | 8 |
| Extensibility / Sponsoring | 7 |

Ask order is by how much each gates: cross-module boundary questions first, then
modelling detail, then post-release modules.

## Committed follow-up work

Two items are now required regardless of what else is decided:

- **CI needs a PostgreSQL service** (ADR-0020). *Update: the local suite now
  runs against Sail's PostgreSQL `testing` database — the remaining work is
  the CI pipeline itself.*
- **`docs/ROADMAP.md` Phase 2 is out of date.** *Done: Phase 1 checklist
  reflects completion, Phase 2 carries the kernel start, ADR-0019 and
  ADR-0024 on Competition.*
