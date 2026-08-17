# Session handoff — 2026-08-17 (second session of the day)

> Point-in-time note for continuing the Phase 2 build.
> Superseded by the next session's work; safe to update or delete once
> resumed. The durable plan lives in `docs/ROADMAP.md` ("Build order to
> the definition of done"); per-module state lives in each `TODO.md`.

## Where things stand

Slices 1–10, 12, 13 and 17 are complete, plus the admin/UI sweep and
slice 17's storage work from the earlier session today. The verbose
history of those lives in this file's previous revisions
(`git log docs/HANDOFF.md`).

Still open in build order: **slice 14** (Compliance completion + GDPR
sweep), **slice 11** (GameOps, deliberately on hold), then 15–16.

## This session — closing UI gaps

The roadmap's "UI page catalogue" tracks screens whose backend shipped
but whose pages never did. Two of those entries are now closed.

**Content (`34286f8`, `2f14dc4`).** Slice 12 shipped Content's whole
domain with *no HTTP surface at all* — no controllers, no routes, no
pages, and a registered `latest-news` block whose Vue component did not
exist, so any page carrying it rendered nothing.

- Operator console: entry index (type/state filters), the editor, one
  endpoint per lifecycle Action, and the comment moderation queue. New
  Actions `CreateEntry`/`UpdateEntry`/`ArchiveEntry` — `Archived` was a
  state nothing could reach. `UpdateEntry` deliberately touches neither
  `state` nor `published_at`: fixing a typo in a live announcement must
  not notify everyone again. Legal `transitions` come from the server.
  Authoring (`content.manage-entries`) and publishing
  (`content.publish-entries`) are separately enforced, with a test for
  an author who may edit and may not publish.
- Public pages: `/news`, `/news/{slug}` with comments, and the
  urgent-announcement banner. Two rules on every read — live-only via
  `scopeLive`, and audience, which **404s** rather than redirecting to
  login (a redirect confirms the slug exists; D-CNT-07 makes audience a
  visibility rule, not a paywall). The banner fetches once per browser
  page load and mounts under `PublicTopbar` through the module component
  registry, the same seam Messaging's bell uses.
- Author read model (`content_authors`, `ProjectAuthor`) from Identity's
  `UserRegistered`/`UserProfileUpdated` — display name only.
- Entry bodies render as plain text with preserved line breaks, never
  `v-html`; rich text needs a sanitizer decision first (Content TODO).

**Registration types + quotas (`f9f47e4`).** The offer itself had no
screen: types came from a seeder or tinker. Now there is a type index,
a type editor (axes, grants, price declaration, quota links) and a quota
screen. Three invariants moved into the Actions, so tinker is refused on
the same grounds as the form:

- a priced settlement with no price declaration (ADR-0017) — otherwise
  `RequestSettlement` throws at submission, in front of a registrant;
- an `on_payment` gate on a settlement nobody pays — nothing would ever
  confirm such a registration;
- **terms freeze once registrations exist.** Event, approval,
  settlement, gate, terms days, grants and price stop being editable
  (name, visibility and quota links stay). `ApproveRegistration` and
  `RecordOrderPaidForRegistration` read the type's *current* axes, so
  editing them under live registrations reinterprets those
  registrations rather than migrating them.

Quota capacity may not fall below what is consumed. `EventSummary`
(projected from `EventCreated`/`EventDetailsUpdated`/`EventArchived`)
gives the type editor an event picker without reading Event's models.

Also: the admin sidebar's icon map gained the eight identifiers modules
were already sending (mail, landmark, hard-drive, book-open-check,
newspaper, message-square, ticket, gauge) — all had been silently
falling back to the puzzle-piece icon.

Verification at handoff: **856 PHP tests + 22 TS tests passing (1 PHP
skip), PHPStan clean, pint clean, `npm run build` green.** Note the
frontend caveat below about `vue-tsc` coverage.

## Next up

1. **Remaining UI-catalogue gaps**, if the UI pass continues: invitation
   tokens and amendment admin (both belong on the new type editor —
   amendment also needs a `registration.amend-registrations`
   capability), participant "my registrations", the Admission operator
   surface (checkpoint/device enrolment, check-in log, badge templates),
   and the three gated modules whose domains shipped without screens
   (Resources, Staffing, Competition).
2. **Slice 14 — Compliance completion + GDPR sweep** (touches every
   module; Compliance's `TODO.md` Backlog is the authoritative list).
3. Slice 11 GameOps stays on hold; then 15–16.

## Loose threads worth knowing about

- **Module Vue files are not type-checked or linted.** `tsconfig.json`
  includes only `resources/js/**`, so `vue-tsc` never sees
  `app-modules/*/resources/js/**`, and ESLint's project service fails to
  parse those files ("was not found by the project service") — which
  also means the ADR-0008 palette rule never runs on them. Adding the
  glob surfaces ~44 pre-existing errors across Event, Messaging and
  Platform pages (mostly `string | null` into `Input`'s `model-value`,
  plus `Cannot find namespace 'App'` in the media page). Both new page
  sets in this session were verified by temporarily adding the glob and
  are clean; the repo-wide fix is still owed.
- Identity owes a **profile-replay command** (`identity:replay-profiles`):
  Messaging's recipients and Content's authors both only see accounts
  created after their projection existed, and neither module may read
  Identity's tables. One replay closes both — it is in Identity's TODO.
- A frozen registration type cannot be superseded from the console; the
  replacement must be built by hand. A "duplicate this type" action is
  in Registration's TODO.
- Grants take a free-text `role`, so a typo becomes a badge nobody can
  check in. A role picker needs a contract with Admission.
- Registration's public form still posts `days`-less; invitation tokens
  and amendment remain tinker-only.
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
  row before it.
- The Boost `database-schema` MCP tool cannot reach pgsql from outside
  the containers — read migrations instead.
- Tests run against a `testing` **Postgres** database, not SQLite:
  `phpunit.xml` overrides `DB_DATABASE` only, never `DB_CONNECTION`.
- `QUEUE_CONNECTION=sync` in `phpunit.xml` runs jobs inline. A test that
  settles money for real must `Queue::fake()`, or
  `GenerateInvoiceDocument` executes and fails resolving brand context.
- `BROADCAST_CONNECTION=null` and `MAIL_MAILER=array` in `phpunit.xml` —
  broadcasting and mail are safe to exercise for real in tests.
- The user works in parallel in the same working tree and testing
  database: never commit their uncommitted files, and re-run a red (or
  suspiciously slow) suite before believing it.
