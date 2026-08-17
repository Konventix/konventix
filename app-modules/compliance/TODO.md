# Compliance — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

Phase 1 needs only consent capture, so the registration flow is lawful. The
fan-out machinery is Phase 2, but the handler contract should exist early — every
module built before it will otherwise be retrofitted.

- [x] Policy and PolicyVersion models per brand with ULID keys (ADR-0014) and
      `BelongsToBrand` (ADR-0010): effective date, published text, required-consent
      flag, publication as an Action.
- [x] Consent records with a subject ULID that tolerates subjects who have no
      user account. The consent capture UI component the registration flow
      embeds is **not** built yet — moved to Backlog below.
- [x] `GdprHandler` contract and the registry modules register into from their
      own ServiceProvider (ADR-0003). Compliance addresses handlers by event and
      never queries a foreign table.
- [x] DataSubjectRequest entity and workflow: request states, statutory
      deadline, per-module response tracking, and re-dispatch of a partially
      failed fan-out — handlers must be idempotent.

## Backlog

- [ ] Export assembly from handler contributions, archive storage, expiring
      download link, and `DataExportCompleted` so Messaging can notify the
      subject. This is also where `GdprHandlerRegistry::all()` gets its first
      real caller — nothing calls `exportFor()` yet.
- [ ] Audit trail per the hybrid mechanism settled in `SPEC.md` (decision log
      Round 6, D-BATCH): the wildcard subscriber over auditable-marked events,
      **plus** an explicit recorder contract modules call for eventless
      actions (list views, CSV exports) — implement both halves, not just the
      subscriber.
- [ ] Anonymize-with-tombstone as the default deletion mode (decision log
      Round 6, D-BATCH): `UserDeletionRequested`'s `anonymize` path must leave
      a tombstone row per affected table so foreign ULID references (e.g. an
      `Admission.holder_user_id`) stay resolvable rather than dangling.
- [ ] DSR deadline scheduler (decision log Round 6, D-BATCH): a scheduled job
      that watches each open `DataSubjectRequest`'s statutory deadline and
      escalates through Messaging as it approaches/lapses.
- [ ] Retention rules plus the scheduled job that emits `RetentionPeriodElapsed`
      per data category, with `EventConcluded` as the first clock start.
- [ ] The subject-request console, registered with Platform's navigation
      registry next to the policy console. (Authorization stays capability
      gates in the controllers — `Gate::authorize(Permission::X->value)` —
      which is what every other module does; a `src/Policies/` class would
      only pay off for per-row decisions, and no Compliance row has one.)
- [ ] Policy *withdrawal*: SPEC proposes `PolicyWithdrawn`, but nothing can
      un-publish a version today. A brand that publishes the wrong text must
      publish a corrected version over it. Needs the event class first.
- [ ] The consent gate is opt-in per route (`require-consent`) and only
      Registration's public form carries it. Every other route a person can
      reach without having agreed is a decision nobody has made yet — walk
      the route list once the DSR half lands.
- [ ] The policy editor takes markdown as a textarea with a server-rendered
      preview behind a toggle. Live preview needs a client-side renderer,
      which means a second markdown implementation and a second XSS surface
      — worth it only if operators complain.
- [ ] `RectificationRequested` event: SPEC's "Public contract" table only
      proposes `DataExportRequested`/`UserDeletionRequested`, so
      `OpenSubjectRequest` currently opens a `rectification`-type request
      without dispatching anything. Define the event in SPEC first, then wire
      the dispatch.
- [ ] Compliance's own `GdprHandler` for its own personal data (consent
      records, subject requests) — Compliance holds subject ULIDs itself and
      arguably needs to export/anonymize its own rows on a subject request,
      but nothing in the Phase 2 scope registers a handler for `compliance`
      itself yet.
- [ ] Consider a Postgres partial unique index
      (`policy_versions(policy_id) WHERE state = 'published'`) for
      concurrent-write safety. Today "only one published version per policy"
      is enforced solely inside `PublishPolicyVersion`'s transaction, which is
      correct for the single-writer path this Action guarantees but not
      immune to two concurrent direct writes bypassing the Action.
- [ ] `permissions:sync` (ADR-0013) doesn't exist yet anywhere in the app —
      `src/Enums/Permission.php` landed here, but the sync command and the
      seeded per-brand roles that grant its capabilities are still open,
      tracked as Platform's concern.

## Done

- [x] Policy console, public policy pages and the consent gate (slice 14,
      first half) — Compliance had the whole domain and **no HTTP surface at
      all**: the legal texts every registration depends on were seeder rows,
      and `requires_consent` was a column nothing enforced.
      Operator console: policy index, the editor, drafting a version,
      publishing one. Two capabilities, deliberately split — `ManagePolicies`
      writes text nobody is bound by, `PublishPolicy` makes a text binding,
      supersedes the previous one and puts the gate in front of every user.
      A published version is **immutable** (`UpdatePolicyVersion` refuses,
      `PublishedPolicyVersionIsImmutableException`): a Consent row points at
      a version id and means "agreed to *this* text", so editing under it
      would rewrite what people agreed to. A policy's slug freezes the same
      way once anything has published, because consent records and published
      links resolve through it.
      `PublishPolicyVersion` now escapes `BrandScope` for its supersede
      update and its policy read — publishing happens on the admin host,
      where the scope hardens to zero rows, so the scoped version left two
      published versions of one policy standing (.ai/rules).
      Public surface: `/policies/{slug}` renders the published version only,
      and only once its effective date has arrived. Text is markdown,
      rendered server-side by `Support\PolicyMarkdown` — CommonMark with
      `html_input: strip` and `allow_unsafe_links: false`, which is what
      makes it the one `v-html` in the application (`league/commonmark`
      promoted to a direct dependency for it).
      The gate: `EnsurePoliciesAccepted`, aliased `require-consent` by this
      module's provider, applied by *consuming* modules to their own routes
      (Registration's public form is the first). One checkbox per policy on
      `/policies-required`, never a bundled "agree to everything" — Art. 7(2)
      GDPR. `GrantConsent` is idempotent per (subject, live version), so a
      double-submitted form is one agreement; a re-grant after withdrawal
      still writes a new row.
      `settings/Privacy` lists what the account agreed to, when, and in what
      context, keeping withdrawn rows — "agreed on the 3rd, withdrew on the
      9th" is the fact. Withdrawing a required consent re-raises the gate,
      which the page says before the button is pressed.
      `Support\OutstandingConsents` is the single definition of "still owed",
      shared by the middleware, the page and the settings screen: two
      implementations would eventually disagree, and that disagreement is
      either a redirect loop or an unenforced legal requirement.
      `tests/{PolicyAdminTest,ConsentGateTest}.php`.
