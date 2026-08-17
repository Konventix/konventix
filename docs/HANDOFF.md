# Session handoff — 2026-08-17 (third session of the day)

> Point-in-time note for continuing the Phase 2 build.
> Superseded by the next session's work; safe to update or delete once
> resumed. The durable plan lives in `docs/ROADMAP.md` ("Build order to
> the definition of done"); per-module state lives in each `TODO.md`.

## Where things stand

Slices 1–10, 12, 13, **14** and 17 are complete, plus the admin/UI sweep
and the two UI-gap slices from the previous session (Content console and
public pages; Registration types and quotas). Slice 11 (GameOps) stays
deliberately on hold; 15–16 are open.

**Slice 14 landed this session, in six commits.** Compliance had its whole
domain — policies, versions, consents, the DSR workflow, the GDPR handler
registry — and *no HTTP surface at all*. The legal texts every registration
depends on were seeder rows, `requires_consent` was a column nothing
enforced, and `DataExportRequested` was an announcement nobody acted on.

1. **Policy console and the consent gate** (`2ddb751`). Policy index and
   editor, drafting and publishing as separate capabilities. A published
   version is immutable — a Consent row means "agreed to *this* text" — and
   a policy's slug freezes on first publication. Public `/policies/{slug}`
   renders markdown through `Support\PolicyMarkdown` (CommonMark,
   `html_input: strip`, no unsafe links), which makes it the one `v-html` in
   the application; `league/commonmark` is now a direct dependency.
   `EnsurePoliciesAccepted` is aliased `require-consent` and applied by
   *consuming* modules — Registration's public form is the first. One
   checkbox per policy (Art. 7(2)), never a bundled agreement.
   `settings/Privacy` shows what an account agreed to, keeping withdrawn
   rows.
2. **The fan-out runs, and the queue has a console** (`a53d038`).
   `AssembleSubjectExport` / `RunSubjectDeletion` call every registered
   handler through the contract, record each answer per module, and close
   only when all succeeded — a failed module leaves the request open with no
   partial archive written. Both are reached through a listener on
   Compliance's own events, so re-dispatch runs the identical path. Archives
   are JSON on a new private `exports` disk with a 14-day expiry. Console at
   `/admin/subject-requests` with three separate capabilities: reading the
   queue, acting on it, and reading an export's contents.
3. **The hybrid audit trail** (`c0f7a74`). `Audit\AuditableEvent` marker +
   one wildcard subscriber, plus `Audit\AuditRecorder` for privileged reads
   with no event (the registrant CSV export, a subject-export download).
   `Audit\` joins `Gdpr\` as a sanctioned cross-module contract surface in
   the architecture test. Not brand-scoped: the trail spans brands and half
   its rows are written on the operator host.
4. **Retention rules, clocks and the daily sweep** (`8cbcf35`). A rule names
   a *data category*, never a table; the owning module deletes its own rows
   on `RetentionPeriodElapsed`. Both D-CPL-01 numbers ship as seeded
   operator-wide rules (`UNIQUE NULLS NOT DISTINCT`, or the operator-wide
   rows would be unconstrained). Two clocks: `record_age` and
   `event_concluded` (fed by Event's own event). The sweep also deletes
   expired export archives.
5. **A `Gdpr/` handler in every module** holding personal data (`5e2f849`) —
   identity, admission, content, messaging, compliance, staffing,
   competition, joining registration's. Identity's carries the hard question
   (below). The refusals are argued in the handlers: Compliance deletes
   nothing, Messaging keeps suppressions, Content keeps comment bodies.
6. **Subject notices and the deadline watch** (`5785478`). Export-ready,
   deletion-acknowledged and refusal notices, plus
   `compliance:watch-deadlines` escalating twice (approaching, overdue) to
   the operator's contact address.

Verification at handoff: **920 PHP tests passing (1 skip)**, PHPStan clean,
pint clean, `npm run build` green.

## Decisions worth knowing (they will look arbitrary otherwise)

- **The consent gate is opt-in per route**, not global. A blanket gate on the
  `web` group makes the policy page itself a redirect candidate, which is how
  consent walls become loops. Only Registration's public form carries it
  today; every other route is an unmade decision (Compliance TODO).
- **Deletion is acknowledged when it starts.** By completion, every module
  has scrubbed the person's contact details, so a "your deletion is done"
  mail would have nowhere to go. The notify listener is registered *before*
  the fan-out for exactly this reason.
- **Identity anonymizes only when the last brand membership goes.** An
  account spans the operator (ADR-0016) but the fan-out arrives per brand;
  anonymizing on one brand's request would end the person's participation in
  the others. The rule becomes redundant, not wrong, once requests are opened
  operator-wide.
- **Compliance's own handler deletes nothing.** Destroying consent proof to
  honour a deletion request would destroy the evidence the request was
  honoured.
- **The retention sweep re-emits daily** while a rule stays elapsed. A
  delete-older-than is idempotent; the alternative is state whose failure
  mode is data that never gets deleted.

## Next up

1. **Slice 15** — Identity social login (Steam + Google + Discord, D-IDN-01)
   plus Platform polish (dark-mode token overrides, operator settings page,
   `brands.operator_id` tightening). Note slice 14 gave Platform's
   `OperatorSettings` a `contactEmailForBrand()`, so the operator settings
   page has a doorway to edit against.
2. **Remaining UI-catalogue gaps** if the UI pass resumes instead: invitation
   tokens and amendment admin (both belong on Registration's type editor;
   amendment also needs a `registration.amend-registrations` capability),
   participant "my registrations", the Admission operator surface, and the
   three gated modules whose domains shipped without screens (Resources,
   Staffing, Competition).
3. Slice 11 GameOps stays on hold; then 16.

## Loose threads worth knowing about

- **Commerce has no GDPR handler**, and that is a finding rather than an
  oversight: an order is keyed by `settles_type`/`settles_id`, so Commerce
  cannot resolve a subject to their invoices without reading Registration's
  tables (ADR-0004 forbids it). Either Commerce keeps a subject read model or
  Registration's export carries the invoice references. An issued invoice may
  not be deleted at all (§147 AO), so this is about export, not erasure.
- **A subject request is still brand-scoped** while Round 6 settled that it
  spans the operator. A person registered with two brands gets two archives.
  Compliance's TODO carries it.
- **Content's public pages never opted out of the app layout.** Event's
  `Landing.vue` sets `defineOptions({ layout: null })`; `content/Index.vue`
  and `content/Show.vue` do not, so they render their own `PublicTopbar`
  inside the authenticated app shell. Compliance's new public pages do it
  correctly — the Content pages are a one-line fix nobody has made.
- **Module Vue files are still not type-checked or linted.** `tsconfig.json`
  includes only `resources/js/**`. Adding the glob surfaces ~44 pre-existing
  errors across Event, Messaging and Platform pages. This session's pages
  were verified by temporarily adding the glob: clean apart from the known
  `defineOptions({ layout: null })` typing gap that Event and Commerce pages
  already have.
- Identity still owes `identity:replay-profiles`: Messaging's recipients and
  Content's authors only see accounts created after their projection existed.
  Slice 14 made this visible — a subject with no recipient row gets no
  notice, logged rather than thrown.
- Registration's public form still posts `days`-less; invitation tokens and
  amendment remain tinker-only.
- Messaging gaps unchanged (recipient timezone, D-BATCH-7 inactive-module
  filtering, announcement audience narrowing, suppression/log operator
  screens, provider bounce webhooks, real-browser push pass).
- `npm run lint` still reports pre-existing ADR-0008 violations in other
  modules' Vue files.

## Resuming on another machine

```bash
git clone git@github.com:Konventix/konventix-poc.git konventix && cd konventix
cp .env.example .env            # MUST precede `sail up` — see below
./vendor/bin/sail up -d         # needs an existing vendor/ — see below
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm ci && ./vendor/bin/sail npm run build
./vendor/bin/sail artisan test --compact
```

**The `.env` copy is load-bearing, not cosmetic.** compose.yaml feeds
`${DB_USERNAME}`/`${DB_DATABASE}` to the pgsql service, and Postgres honours
them only during first-time `initdb`. Start the stack without a `.env` and
the cluster initializes as the default `postgres` role and every later boot
logs `Skipping initialization`; migrations then fail with
`SQLSTATE[08006] … role "konventix" does not exist` — a poisoned volume,
fixed only by `docker compose down && docker volume rm
konventix-poc_sail-pgsql`. Full detail in `.ai/rules/general.md`.

First-time bootstrap without `vendor/`: run composer through a throwaway
container (`docker run --rm -v $(pwd):/app -w /app laravelsail/php85-composer
composer install`) so `./vendor/bin/sail` exists. Ports: the host stack
assumes 80/5173/5432/6379/8090 free; `FORWARD_REVERB_PORT` dodges the
8080-range clashes (see `.ai/rules/general.md`).

Working conventions that matter mid-stream:

- Read `CLAUDE_extra.md` and `.ai/rules/index.md` first — `listeners.md`
  matters most (explicit registration only; modular event discovery is
  off and must stay off).
- One slice per commit, push after each; end every slice with
  `pint --dirty`, `composer types:check`, the full `artisan test
  --compact`, `npm run build`, and TODO.md/SPEC.md updates in the same
  commit.
- `composer types:generate` after touching `#[TypeScript]` Data classes,
  and `artisan wayfinder:generate --with-form` after adding routes (the
  `--with-form` flag is not optional — plain `wayfinder:generate` drops
  the `.form` variants and breaks `vue-tsc` across the auth pages).
- New module pages need `npm run build` before their feature tests pass:
  Inertia resolves them through the Vite manifest.
- An admin-host request leaves **no resolved brand** behind, so a test
  that needs a brand-scoped factory after such a request must create the
  row before it. `operators.admin_host` is unique — a test creating two
  operators needs two hosts.
- The Boost `database-schema` MCP tool cannot reach pgsql from outside
  the containers — read migrations instead.
- Tests run against a `testing` **Postgres** database, not SQLite:
  `phpunit.xml` overrides `DB_DATABASE` only, never `DB_CONNECTION`.
- `QUEUE_CONNECTION=sync` in `phpunit.xml` runs jobs inline. That now means
  opening a subject request carries out its whole fan-out during the call —
  `Queue::fake()` where a test asserts the state *before* the handlers run.
- Postgres aborts the surrounding transaction after a constraint violation,
  so a test cannot query the database after asserting a 500 from one.
- `BROADCAST_CONNECTION=null` and `MAIL_MAILER=array` in `phpunit.xml` —
  mail is safe to exercise for real, and `Mail::mailer()->getSymfonyTransport()
  ->messages()` is how to assert what actually went out.
- The user works in parallel in the same working tree and testing
  database: never commit their uncommitted files, and re-run a red (or
  suspiciously slow) suite before believing it.
