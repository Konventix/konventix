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

- [ ] Consent capture UI component (Vue) for the registration flow to embed —
      deferred out of the Phase 2 kernel work above; needs a `Data` object
      (ADR-0009) wrapping `GrantConsent`/`WithdrawConsent`, plus a controller
      and route.
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
- [ ] Policies (`src/Policies/`) and the subject-request console registered
      with Platform's navigation registry, built on the capability catalogue
      below.
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

<!-- Move completed items here with the PR that finished them. -->
