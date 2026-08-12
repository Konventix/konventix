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
      (every request runs in a brand or 404s), Caddy on-demand TLS `ask`
      endpoint refuses unknown hosts, dev web role runs FrankenPHP via
      Octane. Outstanding: the production Caddyfile wiring `on_demand_tls`
      to `/caddy/ask` (deployment config, lands with the prod image).
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
  (module-grouped collapsible sidebar, search, server-persisted favorites),
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
14. Compliance completion + GDPR sweep: consent UI, export assembly, hybrid
    audit trail, retention rules incl. D-CPL-01 numbers, DSR scheduler, and
    a `src/Gdpr/` handler in every module.
15. Identity social login (Steam + Google + Discord, D-IDN-01) + Platform
    polish (dark-mode token overrides, compliant default status colors,
    operator provisioning tightens `brands.operator_id`).
16. Hardening + dry run: CI with PostgreSQL, `konventix:seed` for both
    event archetypes, BFSG pass, production Caddyfile, then SXLan and
    Xpertise rehearsed end-to-end on seeded data.

## Phase 3 — post-release

Community, Agenda, Sponsoring, Newsletter, Extensibility (external
webhook/OAuth API only after internal contracts are stable), BFSG audit pass,
optional multi-operator groundwork.

## Standing constraints

- BFSG accessibility: token contrast validation from the start (ADR-0008).
- No fund flows through the platform, ever (ADR-0007).
- Module status is tracked in each module's `TODO.md`, updated in the PR that
  changes the code — no generated dashboards.
