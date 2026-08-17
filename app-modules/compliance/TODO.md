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

- [ ] Messaging delivery for `DataExportCompleted` / `UserDeletionCompleted`
      / `SubjectRequestRefused`: the events exist and carry what a template
      needs, but nobody listens, so a subject learns their export is ready by
      revisiting the privacy page. Needs a topic (ADR-0041) and templates.
- [ ] Expired export archives are never deleted — `archive_expires_at` stops
      the download, and the file stays on the disk. The retention job below is
      the right home for sweeping them, which is why this waits for it.
- [ ] `AssembleSubjectExport` treats a subject request as brand-scoped, but
      Round 6 (D-BATCH) settled that a request *spans the operator*
      (ADR-0016). A person registered with two of an operator's brands gets
      two archives today. Closing that means opening one request per brand
      from one form, or an archive assembled across brands — decide which
      before an operator runs two brands in production.
- [ ] Only four actions are marked auditable so far (policy publication,
      deletion dispatch, request refusal, registration approve/reject) plus
      two recorded reads (the registrant CSV export, a subject-export
      download). Walk each module's privileged actions once and mark what
      belongs in the trail — the mechanism is done, the coverage is not.
- [ ] The audit trail has no retention enforcement yet: D-CPL-01 says 36
      months, and nothing deletes. Lands with the retention machinery below.
- [ ] Anonymize-with-tombstone as the default deletion mode (decision log
      Round 6, D-BATCH): `UserDeletionRequested`'s `anonymize` path must leave
      a tombstone row per affected table so foreign ULID references (e.g. an
      `Admission.holder_user_id`) stay resolvable rather than dangling.
- [ ] DSR deadline scheduler (decision log Round 6, D-BATCH): a scheduled job
      that watches each open `DataSubjectRequest`'s statutory deadline and
      escalates through Messaging as it approaches/lapses.
- [ ] Retention rules plus the scheduled job that emits `RetentionPeriodElapsed`
      per data category, with `EventConcluded` as the first clock start.
- [ ] Authorization stays capability gates in the controllers
      (`Gate::authorize(Permission::X->value)`), which is what every other
      module does — `src/Policies/` is still empty and would only pay off for
      per-row decisions, which no Compliance row has. Revisit if one appears.
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
- [ ] A rectification request opens and then sits there: the console can
      refuse it or mark it handled, but nothing fans out (see the
      `RectificationRequested` item below) and no module can act on one.
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

- [x] The hybrid audit trail (Round 6 D-BATCH), both halves.
      Subscriber half: `Audit\AuditableEvent` is a marker an event
      implements, and one wildcard `Event::listen('*')` subscriber records
      whatever declares itself auditable. A list of event classes in
      Compliance would be a second place to keep in sync, maintained by the
      module least likely to remember it exists.
      Recorder half: `Audit\AuditRecorder` for actions with no domain event
      to hear — streaming the registrant CSV, downloading a subject's export.
      ADR-0004 pushes hard towards events and wins for state changes; these
      reads are the residue it cannot cover, and they are exactly the access
      worth recording. Not an Action, deliberately: an audit write must never
      join the caller's rollback.
      `Audit\` joins `Gdpr\` as a sanctioned cross-module contract surface
      in the architecture test — a module implements the marker on its own
      event and keeps owning what the record says.
      `audit_log_entries` is **not** brand-scoped: the trail spans brands by
      design and half its rows are recorded on the operator host where no
      brand exists, so `brand_id` is nullable data on the row. Read-only
      console at `/admin/audit` behind `compliance.view-audit-log` — one
      filtered page over one table, where LanCore had ten per-entity audit
      screens. `tests/AuditTrailTest.php`.

- [x] The fan-out actually runs, and the queue has a console (slice 14,
      second half). `DataExportRequested`/`UserDeletionRequested` were
      announcements nobody acted on: `GdprHandlerRegistry::all()` had no
      caller and `exportFor()` was dead code.
      `Jobs\AssembleSubjectExport` and `Jobs\RunSubjectDeletion` call every
      registered handler through the contract — never a foreign table
      (ADR-0004) — record each answer per module, and close the request only
      when all of them succeeded. A failed module leaves the request
      `in_progress` with no archive written: a partial archive presented as
      "your data" is worse than a late one.
      Both jobs are reached through `Listeners\DispatchSubjectRequestFanOut`
      rather than called from `OpenSubjectRequest`, so re-dispatch runs the
      identical path — a retry down a second code path is the one that rots.
      `afterCommit()` matters: the events fire inside the opening
      transaction.
      Archives are JSON (Art. 20 "commonly used, machine-readable"), one key
      per module, on a new private `exports` disk with the disk sealed beside
      the path (ADR-0043) and a 14-day expiry — the archive is a second copy
      of a person's data that exists to be handed over once.
      Console: the queue sorted by statutory deadline showing days left, the
      per-module response detail (including modules that registered a handler
      after the request opened, which re-dispatch fixes), re-dispatch, refusal
      with a mandatory legal ground (`RefuseSubjectRequest` →
      `SubjectRequestRefused`), and the archive download. Three capabilities:
      reading the queue, acting on it, and reading an export's contents are
      separately granted — that file is every module's answer about one
      person.
      Self-service on `settings/Privacy`: request a copy or deletion, one
      open request per type, and download the archive while it lasts.
      Deletion always opens `anonymize` (Round 6 D-BATCH); erasure conflicts
      with §147 AO retention, so it stays an operator decision rather than a
      button. New events `DataExportCompleted`, `UserDeletionCompleted`,
      `SubjectRequestRefused`.
      `tests/{SubjectRequestFulfilmentTest,SubjectRequestConsoleTest}.php`.
      `ModuleResponseTrackingTest` gained `Queue::fake()` where it asserts the
      *seeding* — the sync queue now carries a request to fulfilled before the
      assertion would otherwise run.

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
