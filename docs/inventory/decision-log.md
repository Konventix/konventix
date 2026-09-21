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

### D-IDN-01 amended (2026-08-19): Steam only

Round 7 recorded Steam + Google + Discord. That is now **Steam alone**, with
ordinary email/password signup and login as the other — and universal — way
into an account.

Google and Discord are **dropped, not deferred**. Nothing is waiting on them;
if either is wanted later it is a new decision, and the `SocialProvider`
contract exists so that decision costs a driver class and a line of
registration rather than a rewrite.

What the narrowing changed in the build:

- **No per-brand credentials.** Steam is OpenID 2.0: no app registration, no
  client id, no secret. The ADR-0028 pattern (encrypted per-brand rows) that
  Google and Discord would each have needed is not required at all — a brand's
  own domain is the entire configuration. The one optional credential is a
  Steam Web API key, for display name and avatar.
- **We supply the CSRF nonce.** OpenID 2.0 has no OAuth `state` parameter, so
  the driver is stateless and the protection against account-linking CSRF is
  ours: a nonce issued at redirect, carried in the signed `return_to`, and
  consumed once at the callback.
- **`users.password` is nullable.** A Steam-first account genuinely has no
  password; storing an unusable hash instead would be a lie every later "can
  this person log in by mail?" question would have to decode.
- **No auto-linking by email.** Steam asserts no address, so a typed address
  that matches an existing account proves nothing — linking on a match would be
  account takeover by typing.

The contract kept its shape despite one driver: `SocialProvider` is modelled on
the *hardest* case (no email, no profile, no state) so an OAuth2 provider fits
inside it rather than forcing it wider.

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

## Round 8 — brand presence (2026-09-07, GitLab #66)

Closes the last question ADR-0016 left cascading, and the four go-live
questions #66 raised about what one operator's brands show and send to a
person who only ever used one of them.

| ID | Decision | Chosen | Status |
|---|---|---|---|
| D-IDP-02 | Consent scope | **Option A — per brand, captured on first use of that brand.** The "three near-identical policies" objection does not arise because a person only meets brand B's policy set when using brand B. Option C (a scope column on `Policy`) stays reopenable. | adr → ADR-0054 |
| #66-1 | Brand B's events in a brand A account | **No. Presence is the membership**: registrations, admissions, profile pages and consents resolve against the current brand's membership and brand-scoped rows; a cross-brand view is an operator capability (ADR-0015). Already shipped behaviour, now the rule. | adr → ADR-0054 |
| #66-2 | Third profile visibility level | **Per brand** — chosen once on `User`, enforced per brand host (ADR-0052 §3, `ResolvesProfiles`). | adr → ADR-0054 |
| #66-3 | Notification consents | **Channel preferences operator-wide, audiences follow the membership.** Announcement fan-out (#25) is the known exception until it lands; marketing opt-ins per brand. | adr → ADR-0054 |
| #66-4 | Is the shared operator recognisable | **Yes, deliberately.** Imprint, seller line and policies already do it; the auth surfaces name the account and list the sibling brands (#184), and the duplicate-registration mail explains it (#18). | adr → ADR-0054 |

### Consequences already known

- **Rejected on the way:** accounts per brand (ADR-0016's one-way door), one
  email with a password per brand, and an unbranded operator login page —
  the last is SSO across brand domains and stays the shape to build if that
  ever becomes a product goal.
- **No migration.** The ADR records shipped behaviour and a rule for future
  surfaces; the code lands in #184 and #18 (Phase 2) and #25 (Phase 5).

## Round 7 — Phase 2 build-out interview (2026-08-12)

Settles everything the Round 6 gate review left open or deferred "until the
paid-flow build" (which shipped in the settlement-chain iteration), plus the
per-module modelling questions that gate the remaining Phase 2 slices.

| ID | Decision | Chosen | Status |
|---|---|---|---|
| D-REG-10 | Amendment of confirmed registrations | **Delta orders: added grants create a new order/invoice against the same registration; the original invoice is never touched. Type changes stay cancel-and-recreate.** | adr → ADR-0036 |
| D-REG-11 | Transfer of invoice recipient | **Editable until the first invoice is issued, frozen after; post-invoice change is a capability-gated credit-note + re-issue flow.** | adr → ADR-0036 |
| D-COM-15 | Refunds vs checked-in admissions | **A checked-in admission blocks the refund** (user override of the report-only recommendation). Commerce answers via its own check-in read model fed by Admission events carrying `registration_id` — never by reading Admission tables. | adr → ADR-0037 |
| D-REG-12 | Quota composition + waitlist semantics | **All-or-nothing across a type's quotas; any exhaustion waitlists the whole submission. FIFO promotion on `QuotaReleased` with time-limited offers (48h default); states queued → offered → converted/expired/withdrawn.** | spec (Registration) |
| D-ADM-05 | Badge layout ownership | **Admission owns per-event `BadgeTemplate` entities** (typed layout, media by ULID per ADR-0035, server-rendered PDF via the existing dompdf pipeline). | spec (Admission) |
| D-STF-02 | Team structure | **Flat team list per event with an area tag; no nesting at release.** | spec (Staffing) |
| D-STF-03 | Lead notes | **No free-form person-notes entity at release.** GDPR export = profile, availability, qualifications, assignment history. | spec (Staffing) |
| D-CMP-14 | Team lifetime | **Teams are per event.** Cross-event identity stays Community territory; `predecessor_event_id` enables later roster-import UX. | spec (Competition) |
| D-CNT-08 | Announcement contract | **Content declares intent only** — urgency (`normal`/`urgent`) + audience (`everyone`/`logged-in`) on `AnnouncementPublished`; Messaging alone maps intent to channels/timing (urgent bypasses digests and quiet hours). | spec (Content, Messaging) |
| D-CPL-01 | Infrastructure-record retention | **Compliance-published rules: delivery log 12 months, audit trail 36 months**, enforced by the retention machinery — one place to adjust. | spec (Compliance, Messaging) |
| D-CNT-09 | Content revisions | **No revision history at release.** The audit trail records who published what when. | spec (Content) |
| D-IDN-01 | Social drivers at release | ~~Steam + Google + Discord~~ → **Steam only** (amended 2026-08-19, see below), via Socialite behind the `SocialProvider` contract. | spec (Identity) |
| D-BATCH-7 | Accepted defaults | **Comments require an account · deactivated-module blocks silently omitted at render, editor shows a warning badge · canonical domain = `is_primary` row · brand tokens standalone, no operator inheritance · in-app notifications filtered at render for inactive modules · GameOps credentials as encrypted per-brand rows (ADR-0028 pattern), provisioning jobs on a dedicated queue with idempotency keys per `MatchScheduled` · resource bookings minute-precision half-open ranges, whole-event as degenerate case · template payload Data changes additive-only, breaking shape ⇒ new template key · ranking/points as typed per-discipline config Data objects.** | spec (various) |

### Consequences already known

- **D-COM-15 orders the build**: refunds cannot ship before Admission's
  check-in chain emits `registration_id`-bearing events (ADR-0037).
- **D-REG-10/D-REG-12 share machinery**: waitlist promotion and amendment
  both materialize additional grants later, each with its own settlement
  request.
- With Round 7, **no inventory decision blocking Phase 2 remains open**.
  Still deliberately deferred: guest registration (D-REG-08), anonymous
  subject identity (follows guest registration), per-event Pennant scoping,
  and everything Phase 3.

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

## Round 8 — Competition surface design (2026-09-18, GitLab #32/#33)

Taken against the module as shipped in slice 10 and SXLAN's measured
tournament history in the Eventula dump. Full text:
`docs/superpowers/specs/2026-09-18-competition-design.md`.

| ID | Decision | Chosen | Status |
|---|---|---|---|
| D-CMP-15 | Competition lifecycle | **Explicit `CompetitionStatus` with enforced transitions; `SignupClosed` between `Published` and `Running`** so signups close before anything starts. (User requirement.) | spec (Competition) |
| D-CMP-16 | Non-bracket contests and stages | **A `leaderboard` stage format (scored entries, optional heats, organizer verification). Multi-stage modelled now — `entry_source`, `advance_count`, a `SeedSource` seam — one stage in the UI.** | spec (Competition) |
| D-CMP-17 | Team formation | **Invite code per team, captain as a member role, a public "interested to play" list, incomplete teams held and resolved by the organizer at signup close (fill from the list or decline). No join requests, e-mail invites or substitutes.** (User spec.) | spec (Competition) |
| D-CMP-18 | Check-in | **Per competition: none / self-service window / desk, plus an optional requirement that the roster was checked in at the event door** via a presence read model from Admission's events. (User decision.) | spec (Competition) |
| D-CMP-19 | Results | **Participants report; agreeing reports record and are then orga-only; differing reports dispute, resolved with a mandatory note; score entries carry optional/required proofs and need verification; corrections cascade and void downstream results; every step is a match-history row.** (User spec.) | spec (Competition) |
| D-CMP-20 | Seeding | **Random by default, drag-order override, regeneration until the first result.** | spec (Competition) |
| D-CMP-21 | Scheduling depth | **`starts_at` plus desk-set `scheduled_at` per match and per round; board and estimator stay #117.** | spec (Competition) |
| D-CMP-22 | Bracket rendering | **Port LanBrackets' canvas onto semantic tokens, table view as the accessible alternative; public pages poll.** (User: porting is okay.) | spec (Competition) |
| D-CMP-23 | Capabilities | **`ManageSchedule`, `CheckInEntrants`, `ViewEntrantContacts` added; Tournament Admin and Tournament Desk as default roles.** Closes #135 for this module. | spec (Competition) |
| D-CMP-24 | Swiss pairing | **Swiss stays available with greedy pairing; blossom deferred until a Swiss competition is planned.** | spec (Competition), #33 |
| D-CMP-25 | Eventula tournaments | **Not imported** — results lived in Challonge, the dump holds none. | — |
| D-GOP-04 (amended) | Catalogue read model | **`Discipline` is the source of record for contest defaults; GameOps' catalogue event carries identity and operational fields only.** | adr → ADR-0062 |
| D-SUP-01 | Feedback scope | **Ship #88 completely — launcher, report, console, reporter access, mails — ordered in four slices; no cutover-week subset.** (User decision 2026-09-21.) | spec (Support design 2026-09-21) |
| D-SUP-02 | Where the launcher lives | **On every brand host and on the operator console; Support becomes non-gateable** because `ModuleGate` reads "no brand" as inactive. (User decision.) | adr → ADR-0063 |
| D-SUP-04 | Context trust boundary | **Server-derived fields overwrite client fields; two correlation ids (page and submission); a five-visit ring buffer supplies the previous page with its own id.** | spec (Support design §5) |
| D-SUP-05 | Guests and abuse | **Guests file with an address; per user/IP and per-address throttles, honeypot answering a fake 201, no stored IP; `CaptchaVerifier` seam with no driver.** (User: keep a captcha in mind.) | spec (Support design §6.4) |
| D-SUP-06 | "Kauf betroffen" alarm | **A metric plus a vmalert rule on the alert channel (#96), and an operator notice on Support's own topic — never Commerce's `commerce.operations` key.** | spec (Support design §7, §10.6) |
| D-SUP-07 | Reporter access | **"My support requests" for accounts; guests get the whole process by mail with 30-day signed links and a self-service re-issue that mails the stored address.** (User decision.) | spec (Support design §8) |
| D-SUP-08 | Privacy and retention | **No screenshots, form contents or IP; 180 days after closing; GDPR export by user id and by address, erasure keeps the row.** | spec (Support design §11) |
| D-SUP-10 | Operator notification | **Every new request notifies triage holders in-app and by mail; the monitoring alert is out of band and additional.** (User decision.) | spec (Support design §7) |
| D-REG-13 | Attendee list on the event page | **Opt-in per person (`users.listed_in_attendees`, default off) and switched per event by the operator (`off`/`members`/`public`, default off); the count may show under the event switch alone, names need both; confirmed registrations only, one entry per person, username only — never display name, e-mail, billing fields or the user ULID; profile link only where Community is active.** (Bugfix round 2026-09-21, Task 15.) | adr → ADR-0064 |
| D-IDP-14 | Names on participant surfaces | **The handle (`@username`) on every public and logged-in page, linked to `/u/{username}` when Community is active for the brand; the real name only in the console and on documents.** Person read models store `username` beside `display_name`. (User decision 2026-09-21.) | adr → ADR-0065 |

## Round 9 — after release 0.7.0 (2026-09-21, operator interview)

Taken on the dev instance after the 2026-09-21 round (plan
`docs/superpowers/plans/2026-09-21-bugfix-round.md`) had rolled out as 0.7.0.
Each item names a follow-up the round left open; the chosen option is the next
round's work.

| ID | Decision | Chosen | Status |
|---|---|---|---|
| D-COM-20 | Prices on public pages outside the shop | **Gross end prices everywhere** — a Commerce enrichment for the event landing page, the `tickets-for-event` block and every other public price, as the shop and cart already have (#234). | next round |
| D-REG-14 | Attendee list defaults | **Both defaults stay off** (per-person opt-in and per-event switch); "members" stays "signed in on the brand host". Confirms D-REG-13. | adr → ADR-0064 |
| D-IDP-15 | Real names in the remaining spots | **Handle everywhere, mails included**: the Community profile wall drops the display name beside the handle; the claim-link mail names the inviter by handle. Real names stay on invoices and tickets only. | next round |
| D-IDP-16 | Passkey scope | **Per site**: the relying party is the request host, a passkey works on the brand host or console it was created on; cross-brand passkeys are not planned. | shipped 0.7.0 |
| D-REG-15 | Changing a registration's quantity | **Partial cancellation with a per-unit refund**, releasing quota and admissions accordingly; the waitlist keeps one unit per person. | next round |
| D-MSG-10 | Mail quota accounting | **Budget per transport**: a brand with its own SMTP transport has its own hourly and daily budget; brands on the default mailer share one, whose daily window rolls over at UTC midnight. | shipped 0.7.0 (UTC day: next round) |
| D-IDP-17 | Sessions list on redis | **Redis with a per-user session index**: a custom session handler keeps a set of session ids per account on login, activity and logout so the list and per-session revoke work on redis as on the database driver. Location stays the IP address. | next round |
| D-IDP-18 | Recovery codes shown once | **Enforced server-side**: Fortify's GET route for the codes is replaced by an Identity-owned path that serves a set only once, right after confirmation or regeneration. | next round |
| D-MSG-11 | Digested announcements | **Each digest item carries its link** to the news entry. | next round |
| D-PLT-12 | Media variants and venues | **`media:regenerate-variants` backfills missing variants after deploy; the venue edit page gets an opening-times editor.** xpertise stays unset on dev. | next round |
| D-REG-16 | Entry conditions, policy bindings, re-consent | **Designed now and built directly on develop, no issues**: one spec plus ADR for entry conditions on registration types and policies bound to an event, a type, a payment provider or an entry condition, and re-consent to contract policies on login or before critical actions. | next round |

## Round 10 — entry conditions, policy bindings, re-consent (2026-09-21, operator interview)

D-REG-16 of Round 9 sent this one to design first. The spec
(`docs/superpowers/specs/2026-09-21-entry-conditions-and-policy-bindings-design.md`)
put twenty-five decision points to the operator, who answered all of them in one
sitting on 2026-09-21. Four answers went against the spec's recommendation
(D-REG-19, D-CPL-03, D-CPL-07 and D-CPL-08 — marked below); the spec now states
every answer as a fact and keeps the rejected recommendation's reason beside it.
ADR-0066 is accepted. The build is Tasks 30–33 of
`docs/superpowers/plans/2026-09-21-round-4.md`, in the spec's §16 order.

| ID | Decision | Chosen | Status |
|---|---|---|---|
| D-REG-17 | Scope of an entry condition | **Brand-wide and reusable**, with an optional `event_id` pinning a one-off condition to a single event. A shared ULID is what a policy binding points at. | Task 30 |
| D-REG-18 | How a type declares its conditions | **A pivot table**: several reusable conditions per type, with a `position` for the order they render in. | Task 30 |
| D-REG-19 | Editing conditions on a live type | **Freely editable after the type has sold** — *against the recommendation to freeze them with ADR-0060's other axes*. The evidence is the assertion snapshot, not the pivot: existing registrations keep their snapshots, a condition added later is "not asserted" for them and the console shows that state, removing a condition keeps the historic assertions, and the type editor warns before saving. | Task 31 |
| D-REG-20 | Assertions when `quantity > 1` | **One assertion per registration** — the registrant asserts for the contractual act; per-holder declarations through claim links are a follow-up. | Task 31 |
| D-REG-21 | Someone will not tick the box | **Refused on submit, plus the alternative-type link**: a nullable `alternative_registration_type_id` drives a "buy the regular ticket instead" link beside the error. | Task 31 |
| D-REG-22 | Verification kinds | **The three kinds** (`self_declared`, `document_at_door`, `operator_checked`); `operator_checked` is **advisory** and fills a worklist. A blocking check is `approval: required` on the type — one mechanism, not two. | Task 31 |
| D-REG-23 | Retention of an assertion | **The registration's own clock**, started by `EventConcluded` and published as a Compliance rule. Never the ten-year bookkeeping floor. | Task 31 |
| D-REG-24 | Registration capabilities | **`ManageEntryConditions` and `VerifyEntryConditions`** — defining a fare condition and judging a document at the door are different jobs. | Task 30 |
| D-ADM-06 | Depth of the door integration | **The label rides on the admission, the scan shows it, the door records `confirmed`/`refused` onto the assertion**, queued offline like every other scan write. A refusal never revokes an admission. | Task 33 |
| D-ADM-07 | Documents and visibility of the label | **Never a document.** Visible to the door and the operator console; the **document hint prints on the ticket PDF**; **nothing on the badge** — the holder needs to know what to bring, the room does not need to know what they paid. | Task 33 |
| D-CPL-02 | Binding target model | **One `kind` plus one `target_key` string** — ULIDs for entities, the provider key for a provider; a fifth kind must not cost a migration. | Task 30 |
| D-CPL-03 | Combining several bindings | **OR within a kind, AND across kinds** — *against the recommendation of a flat OR*. The resolver's rule and the console's wording say so ("applies when any of the events match **and** any of the types match **and** …"), and "Stripe addendum only at event X" is now expressible directly. | Task 30 |
| D-CPL-04 | A policy with no bindings | **Applies everywhere this brand sells** — today's behaviour, unchanged; the console says so out loud, because the empty state is the meaningful one. | Task 30 |
| D-CPL-05 | Binding an operator-wide policy | **Allowed**; the binding carries a `brand_id` where a brand binds the operator's text to its own event, null where the operator binds globally. | Task 30 |
| D-CPL-06 | How the checkout learns types and conditions | **A `binding_context` bag on `RegistrationSettlementRequested`**, persisted on the order line as opaque JSON Commerce never interprets. **No backfill** — existing orders resolve unbound policies only. | Task 32 |
| D-CPL-07 | Provider versus the consent control | **Two-step checkout** — *against the recommendation of a partial reload*: choose the provider, then a confirmation step listing the texts with the accept control. A single-provider brand goes straight to the confirmation step; going back clears the acceptance. | Task 32 |
| D-CPL-08 | The role of a bound text at checkout | **`requires_consent` alone decides** — *against the recommendation of a `checkout_role` column*: any matching bound policy is shown, the version's `requires_consent` decides checkbox versus information, no new role column, ordering `terms`/`withdrawal` first and then the rest by name. | Task 30 |
| D-CPL-09 | What a consent record carries | **`policy_binding_id` plus a `binding_context` snapshot** — "why was this person asked, and for which purchase" becomes one row. | Task 30 |
| D-CPL-10 | Which event carries a declaration | **The per-module events, extended**: `CheckoutConsentGiven` (Commerce) and a new `RegistrationConsentGiven`, both with a binding context. | Task 32 |
| D-CPL-11 | Labelling binding targets in the console | **A Platform `TargetDirectory`** registry each module contributes options and labels to, in `SlotRegistry`'s shape; an unknown target is renderable, not a crash. | Task 32 |
| D-CPL-12 | What marks a text as a contract | **`lawful_basis = contract`, reused** — no second flag that can disagree with the first. | Task 33 |
| D-CPL-13 | Which acts are critical | **Contract-creating acts only**: registration submit, checkout start, cart checkout, amendment, waitlist conversion, ticket-claim acceptance. An outstanding text never costs someone their account, tickets or order history. | Task 33 |
| D-CPL-14 | Where the re-consent gate lives | **A `require-contract-consent` alias**, registered by Compliance and applied by the owning module to its own critical routes, mirroring `require-consent`. | Task 33 |
| D-CPL-15 | The interstitial | **`policies.required`, reused** for login and mid-session, with one line naming the act. No modal — a legal text behind a dismissible overlay is not an agreement. | Task 33 |
| D-CPL-16 | The binding capability | **Compliance's own `ManagePolicyBindings`**, separate from `ManagePolicies`: binding a text to a product changes who is bound by it without changing a word of it. | Task 30 |
| D-CPL-17 | Measuring a refusal | **Derived** from prompts against grants, plus a **"Not now"** button on the interstitial **without its own counter** — a consent screen with only one way out is not a choice. | Task 33 |

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
