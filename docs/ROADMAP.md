# Roadmap

## Phase 0 — before the first commit (blockers)

Cleared.

- [x] IP position settled (ADR-0011). No agreement with the Verein was needed:
      the predecessor (Lan-Software / LanCore) already ran under Markus Kohn's
      own name, so sole copyright was never in question.
- [x] konventix.de, konventix.eu and the GitHub organisation registered and held
      by Markus Kohn. Trademark search (DPMAregister, TMview, EUIPO eSearch)
      still outstanding before public launch — note the phonetically similar
      "conventic GmbH".
- [x] LICENSE (AGPL-3.0-or-later) and this docs set committed.

## Phase 1 — PoC (riskiest unknowns only; no payment, no tournament logic)

Proven:
- [x] Multi-domain brand resolution: ResolveBrand fronts the `web` group
      (every request runs in a brand or 404s), dev web role runs FrankenPHP
      via Octane.

      **TLS is out of scope (decided 2026-08-19).** Konventix runs behind a
      reverse proxy that terminates TLS and is not part of this application,
      so the production Caddyfile wiring `on_demand_tls` to `/caddy/ask` is
      no longer owed — and no such endpoint was ever built. Certificate
      issuance for a newly added brand domain becomes that proxy's business.

      What the application owes instead is already done: it trusts the
      proxy's forwarded headers (`config/trustedproxy.php`), including
      `X-Forwarded-Host`, without which brand resolution reads the proxy's
      internal hostname and every request gets the wrong brand or a 404. The
      trusted list is not a wildcard by default, precisely because a
      spoofable host would let a direct caller choose which brand it is
      talking to.

      Still owed by the deployment, not by this repo: the proxy must forward
      `Host` unmodified, and must be able to obtain a certificate for each
      brand domain.
- [x] Brand-scoped sessions (per-brand cookie names) + brand context
      serialized into queued jobs and restored in workers
- [x] Pennant module gating at all four points (routes, nav, slots,
      listeners — `ShortCircuitsInactiveModule`)
- [x] Block-based static brand pages: `BlockType` contract + registry via the
      `registerBlockTypes()` provider hook, render pipeline at `/pages/{slug}`
- [x] `BelongsToBrand` scope + strict models + laravel-data → TS pipeline
      (`composer types:generate` → `resources/js/types/generated.d.ts`)
- [x] One registration flow with multiple RegistrationTypes (axes model),
      free settlement only: submit → (approve) → confirm emits
      `RegistrationConfirmed`; Admission issues idempotently from the payload
      alone; Commerce stays dormant (ADR-0026)

## Phase 2 — release scope

**Round 6 gate review:** the spec-out session's decision set is now closed
(`docs/inventory/decision-log.md`, "Round 6 — the Phase 2 gate review").
**Round 7 build-out interview (2026-08-12):** everything Round 6 left open
or deferred to the paid-flow build is now settled — amendment/transfer
(ADR-0036), refunds vs check-in (ADR-0037), waitlist semantics, badge
templates, announcement contract, retention numbers, social drivers, and a
batch of accepted defaults. No inventory decision blocking Phase 2 remains
open.
Five user overrides added release scope beyond the recommended minimums: the
event-day concept, multi-venue events, web push, Messaging-owned digests, and
Registration-owned waitlists. See each affected module's `SPEC.md`/`TODO.md`
for what that adds to its definition of done.

Kernel: Platform, Identity, Event, Compliance, Messaging — **started**:
- Operator admin console (ADR-0029): own host resolved into OperatorContext,
  hardened brand scope, `permissions:sync` + Operator Admin role (ADR-0013
  live), module-contributed `routes/admin/` + admin navigation; Brands and
  Users pages shipped. Demo data via `konventix:seed` (Laravel Prompts).
- Identity: **auth lifecycle complete** — registration, email verification,
  2FA (confirmed), session list/revocation, account states, auth Data
  objects; `brand_memberships` (ADR-0016) recorded on login/registration
- LanCore UX adapted (same stack, so ports not rewrites): admin shell
  (module-grouped collapsible sidebar, search, server-persisted favorites the
  user can drag into their own order — ADR-0042, beyond LanCore),
  shared DataTable pattern, brand landing pages as block compositions
  (hero/cards from Platform, event-hero/countdown contributed by Event —
  the ADR-0003 extension proof), PublicTopbar, brand theme editor with
  BFSG contrast enforcement (ADR-0008 "refuses, not warns" — which caught
  and fixed non-compliant default status colors), per-event admin dashboard
  composed via UI slots (Registration/Admission contribute their own cards
  and endpoints). Deferred from LanCore: seat-map viewer (needs Resources),
  audit pages, dark-mode theme overrides.
- Event: Event + Venue models (wall-clock times, ADR-0027), lifecycle
  Actions + contract events, public landing route
- Compliance: policies/versions, consent records, `GdprHandler` contract +
  registry, DataSubjectRequest workflow with idempotent re-dispatch
- Messaging: `MessageChannel` contract + mail driver with per-brand
  transports (ADR-0028), DB-backed template registry with brand overrides,
  outbound queue pipeline
- Realtime transport: shared Reverb, one app per brand from brand rows
  (ADR-0025), per-brand origins enforced

Core: Registration, Admission, Commerce, Content — **invoice-settlement
chain shipped**: ADR-0017 price declaration on RegistrationType,
gate-dependent `RegistrationSettlementRequested`, Commerce order/lines with
ADR-0030 VAT treatment (reverse charge, §19 UStG), gapless per-brand
invoice numbers, hybrid ZUGFeRD PDF/A-3 archival (ADR-0031), manual
payment booking in the operator console, and `OrderPaid` driving
`on_payment` confirmation. Remaining for the exhibitor definition-of-done
item: Messaging delivery of the invoice document.
Modules: Resources, Competition (absorbs LanBrackets per ADR-0019 and owns
the `Discipline` abstraction per ADR-0024), GameOps, Staffing

Definition of done: our own events (SXLan, Xpertise) run end-to-end on
Konventix, including invoice-settled exhibitor registrations, entrance
scanning, seat/resource booking, shift planning, and tournaments.

### Build order to the definition of done

Sixteen slices, dependency-ordered (settled after the Round 7 interview;
each slice lands with tests, TODO.md updates, and pint/phpstan green).
Slices 8, 9 and 12 are independent of 5–7 and of each other, so they may
interleave.

1. Enablers: module-local Inertia page resolution (vite globs over
   `app-modules/*/resources/js/pages`), Platform media library (ADR-0035),
   role-assignment screen — these unblock every module UI, badges, Content.
2. Messaging minimum: `InvoiceIssued` → mail with the archived ZUGFeRD PDF
   attached (first real `SendMessage` consumer), recipient read model —
   closes the exhibitor definition-of-done item.
3. Event completion: multi-venue (D-EVT-15), event-day entity (D-ADM-04),
   re-entry knob (D-ADM-03), `predecessor_event_id` (D-EVT-14), remaining
   lifecycle Actions, admin CRUD. Event-days gate Admission validity.
4. Registration completion: waitlists (D-REG-12), invitation/link tokens
   (D-COM-02), organizer list with bulk approval, amendment/transfer
   (ADR-0036), registrant read model + GDPR exporter.
5. Admission entrance chain: signed tokens + entrance API + delta feed
   (ADR-0033), check-in records **emitting `registration_id`** (the
   ADR-0037 prerequisite), event-day validity, claim links (ADR-0032),
   `BadgeTemplate` + PDF rendering (D-ADM-05).
6. Offline entrance client (ADR-0002 carve-out) — new work, not a port.
7. Commerce completion: Stripe Connect Standard + Mollie adapters,
   `SettlementAccount`, public checkout for `instant`, dunning, refunds +
   credit notes (blocked-by-check-in per ADR-0037; credit notes also serve
   ADR-0036 transfer), bookkeeping export.
8. Resources: types in unit/quantity mode (ADR-0034), half-open bookings
   with exclusion constraints (ADR-0020), claims materialized from grants,
   admin UI, seat-map viewer port.
9. Staffing: flat teams (D-STF-02), shifts, self-service sign-up
   (D-STF-01), `AssignmentAccepted` → crew Admission, shift-plan UI.
10. Competition: `Discipline` (ADR-0024) over a GameOps catalogue read
    model (the catalogue piece of slice 11 lands first), per-event teams
    (D-CMP-13/14), signups on confirmed Registration (D-CMP-05), bracket
    engine absorbed from LanBrackets (ADR-0019), results + corrections
    (D-CMP-11/12).
11. GameOps orchestration: pools with per-pool reuse (D-GOP-01), bounded
    provisioning retries → desk handoff (D-GOP-02), heartbeats +
    `SessionUnhealthy` (D-GOP-03), idempotent jobs, encrypted per-brand
    credentials — consumes Competition's `MatchScheduled`.
12. Content: `ContentEntry` + typed content types, publish Actions, public
    pages, CMS block types, comments (account-required) + moderation,
    announcement intent contract (D-CNT-08).
13. Messaging full: web push driver (D-MSG-02), digests + quiet hours
    (D-MSG-03, urgent bypasses), per-brand delivery log + suppression
    (ADR-0028), in-app inbox.
14. Compliance completion + GDPR sweep — **done**: policy console and the
    opt-in consent gate, export assembly with a real fan-out, the hybrid
    audit trail, retention rules incl. the D-CPL-01 numbers, the DSR deadline
    scheduler, and a `src/Gdpr/` handler in every module holding personal
    data. Left standing: Commerce has no handler (an order cannot resolve a
    subject without reading Registration — see Compliance's TODO), and the
    per-module anonymization log has no screen.
15. Identity social login (**Steam only** — D-IDN-01 as amended 2026-08-19;
    Google and Discord dropped) + Platform polish (dark-mode token overrides,
    operator settings page, `brands.operator_id` tightened to non-null).
    **Done.** Steam landed as slice 15a and the Platform items as 15b, which
    also fixed the dark palette's own `danger-contrast` pair (3.62:1) — the
    first time ADR-0008's guard had been pointed at the platform defaults
    rather than at a brand's overrides.

16. Hardening + dry run: `konventix:seed` for both event archetypes, BFSG
    pass, then SXLan and Xpertise rehearsed end-to-end on seeded data.

    CI with PostgreSQL is **done** (2026-08-20) — and it was not a
    formality. The pipeline had been failing on every commit for a long
    time: `composer setup` migrates, and no database service was defined.
    It now runs Postgres and Redis services and the whole `ci:check` chain
    (eslint, prettier, vue-tsc, pint, PHPStan, the Pest suite) against a
    built frontend. The production Caddyfile left this slice with the TLS
    decision above.
17. Object storage backends (ADR-0043): media ULIDs in page blocks (the
    `hero` block stores a resolved URL today and would rot on any disk
    change — prerequisite for everything below), S3 driver on the `media`
    and `invoices` disks with direct public delivery where the deployment
    declares it, proxied private downloads, queued variant generation,
    brand-scoped disk resolution (ADR-0028 shape, per-brand buckets later),
    `storage:migrate` with verification, a storage doctor command, and
    Garage behind a compose profile as the development backend. May
    interleave with 8–15; the block change should land early regardless.

## Phase 3 — post-release

Community, Agenda, Sponsoring, Newsletter, Extensibility (external
webhook/OAuth API only after internal contracts are stable), BFSG audit pass,
optional multi-operator groundwork.

Also deferred here by decision, not by omission:

- **GoBD retention deletion** (ADR-0031, ADR-0043 point 9). Slice 17 ships
  reporting only — a scheduled job that names archived documents past their
  window, plus a manual command. What deletion *means* (§147 AO counts ten
  years from the end of the calendar year of issue, and a deletion that runs
  early is worse than one that never runs) needs its own decision before any
  operator is near the limit. Konventix has no invoice older than 2026, so
  the honest deadline is far out — but the fix is owed, not waived.

## Standing constraints

- BFSG accessibility: token contrast validation from the start (ADR-0008).
  Enforced in two places: `UpdateBrandTheme` refuses a brand's save (it does
  not warn), and eslint's `konventix/no-palette-colors` refuses a raw palette
  utility in any Vue file — host or module.
- No fund flows through the platform, ever (ADR-0007).
- Module status is tracked in each module's `TODO.md`, updated in the PR that
  changes the code — no generated dashboards.
- Everything is checked, everywhere. The frontend blind spot that let half the
  Vue files go unchecked is closed: `tsconfig.json`, eslint and prettier all
  cover `app-modules/*/resources/js/**` as well as `resources/js/**`, and CI
  runs the lot. A check that silently skips half the codebase is worse than no
  check, because it reports green.

## Known gaps carried forward

Findings that are nobody's slice yet. Each is a decision or a piece of work
that has an owner in a module's `TODO.md` where one exists; this list is only
so they are not rediscovered.

- **Commerce has no GDPR handler**, and that is a finding rather than an
  oversight: an order is keyed by `settles_type`/`settles_id`, so Commerce
  cannot resolve a subject to their invoices without reading Registration's
  tables (ADR-0004 forbids it). Either Commerce keeps a subject read model or
  Registration's export carries the invoice references. An issued invoice may
  not be deleted at all (§147 AO), so this is about export, not erasure.
  (Compliance's `TODO.md`.)
- **A subject request is still brand-scoped** while Round 6 settled that it
  spans the operator, so a person registered with two brands gets two
  archives. (Compliance's `TODO.md`.)
- **Telescope and Pulse hold personal data outside the retention machinery**,
  bounded only by their own prune schedules — a window, not a rule anybody can
  audit. (Compliance's `TODO.md`.)
- **Identity owes `identity:replay-profiles`.** Messaging's recipients and
  Content's authors only see accounts created after their projection existed,
  so a subject with no recipient row gets no notice. One replay closes both
  and every future read model inherits the fix. (Identity's `TODO.md`.)
- **Unlinking a social provider is not re-authenticated.** `password.confirm`
  is unsatisfiable for the passwordless accounts social login creates; the
  lockout guard is what protects the account today. (Identity's `TODO.md`.)
- **Messaging gaps unchanged**: recipient timezone, D-BATCH-7 inactive-module
  filtering, announcement audience narrowing, suppression/log operator
  screens, provider bounce webhooks, a real-browser push pass. (Messaging's
  `TODO.md`.)
- **`Data\OpeningPeriodData` carries no `#[TypeScript]` attribute**, so the
  venues form spells its shape out by hand and the two can drift. Add the
  attribute when the opening-times editor lands.

## UI page catalogue (LanCore port map, audited 2026-08-16)

LanCore and its satellites (LanBrackets, LanHelp, LanShout) shipped roughly
150 Inertia pages; Konventix has ~30 today. This catalogue maps every LanCore
surface to its Konventix home so no screen is silently forgotten. It is a
port *map*, not a port list — pages whose concept Konventix models differently
are marked absorbed, not copied. Detail-level gaps stay tracked in each
module's `TODO.md`; this section only fixes the page inventory and its phase.

Status: **✓** shipped · **S«n»** scheduled in that Phase 2 slice ·
**P2 backlog** = Phase 2, backend done, page unscheduled · **P3** = Phase 3 ·
**absorbed/dropped** = deliberately not ported 1:1.

### Kernel

| Module | Pages to build | LanCore precedent | Status |
| --- | --- | --- | --- |
| Platform | Admin shell, dashboard, Brands, Users, media library, role assignment, theme editor, block-based landing/static pages | admin shell, `themes/*`, `legal/*`, `Welcome`, `Countdown` | ✓ |
| Platform | Dark-mode token overrides in the theme editor | `themes/Edit` (dark palette) | ✓ |
| Platform | Operator settings page (legal seller identity, provisioning) | `settings/Organization` | ✓ |
| Identity | Auth flows, profile/security/appearance settings | `auth/*`, `settings/{Profile,Security,Appearance}` | ✓ |
| Identity | Linked accounts + social onboarding (Steam signup completion) | `settings/LinkedAccounts`, `onboarding/Username`, `auth/CompleteSteamProfile` | ✓ — LanCore's separate username-pick and Steam-profile screens are one `auth/CompleteSocialSignup` |
| Identity | Admin user detail page (memberships, sessions, states) | `users/Show` | P2 backlog |
| Event | Event index/edit/dashboard/lifecycle, venue admin, public landing | `events/*`, `venues/*` | ✓ |
| Compliance | Policy admin CRUD; user-facing consent pages; privacy settings | `admin/policies/*`, `policies/{Required,Show}`, `settings/Privacy` | ✓ |
| Compliance | DSR console: export/deletion requests with their per-module fan-out; self-service request + download on the privacy page | `admin/data-lifecycle/*`, `account/{Delete,DeletionPending}` | ✓ |
| Compliance | Retention-policy admin | `admin/data-lifecycle/*` | ✓ |
| Compliance | Anonymization log (what a deletion actually changed, per module) | `admin/data-lifecycle/*` | S14 |
| Compliance | Audit pages (per-entity change history — LanCore had ten `*/Audit.vue`) | `users/Audit`, `events/Audit`, `news/Audit`, … | ✓ — absorbed into one filtered trail, not ported per entity |
| Messaging | Delivery log + suppression admin | `admin/emails/*` | S13 |
| Messaging | In-app inbox + archive; notification/digest/quiet-hours preferences | `notifications/*`, `settings/{Notifications,EmailSettings}` | S13 |

### Core

| Module | Pages to build | LanCore precedent | Status |
| --- | --- | --- | --- |
| Registration | RegistrationType admin CRUD (axes, price declaration, quotas, grants) | `ticket-types/*`, `ticket-categories/*`, `ticket-addons/*` | P2 backlog |
| Registration | Participant "my registrations" (list + detail, amendment entry point) | `tickets/{Index,Show}` | P2 backlog |
| Registration | Amendment admin UI (ADR-0036), invitation-token management (D-COM-02), waitlist admin | — (new concepts) | P2 backlog |
| Registration | Purchase constraints | `purchase-requirements/*`, `global-purchase-conditions/*`, `payment-provider-conditions/*`, `settings/TicketDiscovery` | absorbed — type axes, quotas and invitation tokens replace LanCore's constraint system; no 1:1 port |
| Admission | Checkpoint/device enrolment, badge template designer (D-ADM-05), claim-link management (ADR-0032), entrance POS screen (ADR-0018) | — (LanEntrance satellite had no admin UI) | P2 backlog |
| Commerce | Orders admin index, settlement-account console, bookkeeping export, public checkout | `orders/Index`, `shop/Settings`, `cart/Checkout` | ✓ |
| Commerce | Order/invoice admin detail page; participant "my orders/invoices"; invoice-prefix settings | `orders/Show`, `my-orders/*` | P2 backlog |
| Commerce | Voucher CRUD + redemption view | `vouchers/*` | P2 backlog (SPEC-owned, unscheduled) |
| Content | Entry editor + admin index, public listing/detail, comment moderation, announcements (+ public banner) | `news/*`, `announcements/*` | ✓ |
| Content | Knowledge-base content type | LanHelp `kb/*` | P3 — lands with Support (ADR-0022) |

### Pennant-gated modules

| Module | Pages to build | LanCore precedent | Status |
| --- | --- | --- | --- |
| Resources | Resource + resource-type admin, seat-plan editor, public seat picker | `seating/*` incl. `Picker` and the block/category editor | P2 backlog (slice 8 shipped the domain) |
| Staffing | Team admin + public team pages, lead roster board, crew "my shifts" | `orga-teams/*` | P2 backlog (slice 9 shipped the domain) |
| Competition | Competition admin CRUD + team management, tournament desk, public schedule/match/standings/team pages, stream bracket overlay | `competitions/*`, `events/CompetitionBoard`, LanBrackets `Competitions/*` + `Overlay/Bracket` | P2 backlog (slice 10 shipped the engine) |
| GameOps | Game catalogue CRUD (+ modes), operations surface (servers, APIs, jobs), participant "play next" portal | `games/*`, `orchestration/*`, `portal/PlayNext` | S11 |

### Phase 3

| Module | Pages to build | LanCore precedent | Status |
| --- | --- | --- | --- |
| Agenda | Program admin CRUD + public agenda | `programs/*` | P3 |
| Community | Chat rooms, achievements CRUD + user showcase, public profiles, presence | `chat/Room`, LanShout, `achievements/*`, `u/Show`, `settings/Achievements` | P3 |
| Sponsoring | Sponsor + sponsor-level CRUD, public sponsor display | `sponsors/*`, `sponsor-levels/*` | P3 |
| Newsletter | List management | `newsletter-lists/*` | P3 |
| Support | Ticket create/list/detail, staff queue, admin views (ADR-0022; the KB half lands in Content instead) | LanHelp `tickets/*`, `staff/*`, LanCore `admin-tickets/*` | P3 |
| Extensibility | Webhook CRUD + delivery detail, OAuth app management | `webhooks/*` | P3 (external contracts only after internal ones are stable) |
| — | Satellite connection screens | `integrations/*` (Create{LanBrackets,LanEntrance,LanHelp,LanShout}) | dropped — the satellites are gone (module-map) |
