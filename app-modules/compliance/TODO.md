# Compliance — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [x] Policy bindings and the resolver — the behaviour-neutral foundation
      (round 4, Task 30, ADR-0066; D-CPL-02/03/04/05/08/09/16).
      `policy_bindings` (`kind` + `target_key`, nullable `brand_id` through
      `MayBeOperatorWide`, unique per `(policy, kind, target)`), the two
      nullable `consents` columns `policy_binding_id` and `binding_context`
      (**no backfill** — a null bag means "asked brand-wide", which is what
      every existing row meant), `PolicyBinding` + factory, `bindings()` on
      `Policy`, `PolicyBindingKind`, `BindPolicy`/`UnbindPolicy`, events
      `PolicyBound`/`PolicyBindingRemoved` (both `AuditableEvent`) and
      `ConsentPromptRaised`, `Permission::ManagePolicyBindings`, and
      `Bindings\PolicyResolver` with `BindingContext` and
      `ResolvedLegalText` — **OR within a kind, AND across kinds**, unbound
      policies always resolving, ordering `terms`/`withdrawal` then by name,
      and `requires_consent` alone deciding agreement versus information
      (no role column; `CheckoutLegalTextRoleData` untouched).
      `CheckoutLegalTexts::forBrand()` and `missingSlugsForBrands()` are
      unchanged and stay the no-context call the #222 gap notice asks. Also
      the module's first metrics catalogue and the *Consent and conditions*
      dashboard row, with `KonventixConsentGateBlocking`. **Nothing resolves
      through the new path yet**: the binding console, the
      `registration.consent` slot and the two-step checkout are Task 32, the
      re-consent gate is Task 33.
- [x] Re-consent to a contract and the door (round 4, Task 33, ADR-0066
      slice B3; D-CPL-12/13/14/15/17). The second alias
      `require-contract-consent` (`Http\Middleware\EnsureContractConsent`)
      beside `require-consent`: the narrow `subjectHasOutstandingContract()`
      question, guests through, `ConsentPromptRaised(route_gate, contract)`,
      a redirect to `policies.required`, and `url.intended` that is never a
      POST — a GET remembers itself and a POST remembers the GET page its
      own route named as the middleware's second parameter. Applied by the
      owning modules to the fourteen contract-creating routes and to none of
      the escape hatches (SPEC.md has the list; `ContractReconsentGateTest`
      pins it). `policies/Required.vue` gains the act's label, fed from the
      session key `compliance.consent_act` the gate writes and
      `ConsentController::store()` clears, and a **"Not now"** control that
      counts nothing. Identity's `RedirectsOutstandingContractConsent` and
      `compliance.consent_redirect` are untouched.
- [x] The bindings console and the consent slots (round 4, Task 32,
      ADR-0066; D-CPL-06/07/08/10/11/16). The *Where this applies* card on
      the policy editor behind `ManagePolicyBindings`
      (`Admin\PolicyBindingController`, `POST /admin/policies/{policy}/bindings`
      and `DELETE /admin/policy-bindings/{binding}`, the card itself in
      `components/PolicyBindingsCard.vue` with the pure half in
      `components/policyBindings.ts`), fed by Platform's `TargetDirectory`;
      the empty-state sentence, the rule in words once a second kind is
      bound, `Unknown (event · 01HX…)` with *Remove* for a dangling target,
      and a "bound" marker on the policy index. `Support\ConsentContribution`
      is the one builder behind both slots, splitting agree from inform on
      `requires_consent`; `components/RegistrationConsent.vue` mirrors
      `CheckoutConsent.vue` for Registration's `registration.consent` slot,
      and `RecordConsentDeclaration` records `RegistrationConsentGiven` the
      way `RecordCheckoutConsent` records the checkout — both resolving the
      context the page rendered and never re-deriving one from the brand.
      `GrantConsent` gained `policyBindingId` and `bindingContext`, both
      defaulted and never rewritten by the idempotent branch.
      `tests/{PolicyBindingConsoleTest,RegistrationConsentSlotTest}.php`.
      `ConsentGranted` gained `capture_context` and `ConsentWithdrawn` a
      nullable `brand_id`, both appended for the counters.
- [ ] Two stores of personal data now sit outside the retention machinery:
      Telescope's request bodies (development only — a `require-dev` package
      that never reaches production) and Pulse's user-keyed aggregates. Both
      are bounded today only by their own trims —
      `telescope:prune --hours=48` on the schedule, and Pulse's own
      `ingest.trim.lottery`/`storage.trim.keep` — which is a
      window, not a rule anybody can see or audit. D-CPL-01 named the delivery
      log and the audit trail; these are the same question asked about
      operational telemetry, and the answer may well be "these are not
      personal data we hold on purpose, so the short window is the rule" —
      but it should be written down as a decision rather than left as a
      default in a service provider.


Phase 1 needs only consent capture, so the registration flow is lawful. The
fan-out machinery is Phase 2, but the handler contract should exist early — every
module built before it will otherwise be retrofitted.

- [x] Operator-wide legal texts (#76). `policies.brand_id` and
      `policy_versions.brand_id` are nullable, with the unique index written by
      hand as `UNIQUE NULLS NOT DISTINCT (brand_id, slug)` — a plain unique
      index treats every NULL as distinct and would leave the operator-wide
      rows, the ones every brand falls back to, unconstrained. The operator is
      one legal entity behind all its brands (ADR-0016), so it writes its AGB
      and Widerrufsbelehrung once; a brand publishes its own under the same
      slug only where it genuinely differs, and that row shadows the
      operator's. `Policy::resolveFor()` is the single place that decides,
      escaping BrandScope with the reason written down (ADR-0010), and every
      public read path goes through it: `/policies/{slug}`,
      `OutstandingConsents`, the consent form, the privacy page.
      `Models\Concerns\MayBeOperatorWide` overrides BelongsToBrand's creating
      hook so a row that explicitly asks for a null brand keeps it while one
      that merely forgot its brand still fails loudly. The operator console
      creates and lists both scopes and says when a brand has overridden an
      operator text — an operator text every brand shadows binds nobody.
      `tests/OperatorPolicyFallbackTest.php`.

- [x] The Widerrufsbelehrung exists (#76). `grep -ri widerruf` used to find
      nothing. `Support\CheckoutLegalTexts` names the two slugs a public
      purchase path asks for — `terms`, which the factories and the demo
      seeder already used, and `withdrawal` beside it — and resolves both
      through the operator fallback. The § 312g Abs. 2 Nr. 9 BGB exemption
      (no right of withdrawal for a leisure event on a fixed date) is a
      constant, not a policy version: an operator must not be able to reword a
      statute, in either direction. `OperatorLegalTextsSeeder` publishes
      operator-wide placeholders for both, every word of which says
      PLACEHOLDER — the real texts are blocked on the imprint release (#91),
      and a placeholder that reads like a finished document is one nobody
      replaces. `tests/WithdrawalPolicyTest.php`.

- [x] The consent gate reaches the two public purchase paths (#2). The cart
      checkout and the registration payment page both require `accept_terms`
      before the order button and record one Consent per purchase text against
      the version in force, context `checkout`. Commerce cannot write that row
      — Compliance's models and Actions are not reachable across the boundary
      (ADR-0004, `tests/Unit/ArchitectureTest.php`) — so Commerce emits
      `CheckoutConsentGiven` and `Listeners\RecordCheckoutConsent` resolves the
      documents and calls `GrantConsent`. The control itself is a
      `checkout.consent` slot contribution, so the wording lives once in the
      module that owns the documents rather than twice in two checkouts. An
      instance with nothing published renders no control, demands nothing and
      records nothing: a checkout must not claim someone agreed to a text that
      does not exist, and a fresh instance must still be able to sell.

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

- [ ] The privacy policy text should link the footer's cookie settings and
      match its declarations (DSK: banner and policy must agree).
- [ ] `UserDeletionCompleted` still notifies nobody, and cannot: by then the
      address is scrubbed. If a "your deletion is done" notice is ever
      required, it needs an address captured at request time and held outside
      the fan-out's reach — which is a copy of personal data kept for the
      purpose of deleting personal data, and needs a decision, not code.
- [ ] The retention sweep re-emits daily for as long as a rule stays elapsed
      (deliberate — a delete-older-than is idempotent, and the alternative is
      state whose failure mode is data that never gets deleted). If the
      event-scoped emissions ever grow past a few hundred a day, the fix is a
      per-clock high-water mark, not a flag on the rule.
- [x] `AssembleSubjectExport` treated a subject request as brand-scoped, but
      Round 6 (D-BATCH) settled that a request *spans the operator*
      (ADR-0050). The fan-out contract now takes a `SubjectRef` without a
      brand, and archives assemble operator-wide (slice 20).
- [ ] Only four actions are marked auditable so far (policy publication,
      deletion dispatch, request refusal, registration approve/reject) plus
      two recorded reads (the registrant CSV export, a subject-export
      download). Walk each module's privileged actions once and mark what
      belongs in the trail — the mechanism is done, the coverage is not.
- [ ] GoBD deletion is still deferred to Phase 3 by decision (ROADMAP):
      the retention machinery now has a home for it, but what §147 AO
      deletion *means* needs its own decision before an invoice is near the
      limit.
- [ ] The deadline escalation goes to the operator's single contact address.
      Per-brand or per-role routing (a data protection officer who is not the
      operator's general contact) needs a settings field; flagged rather than
      invented.
- [ ] Only two data categories have a consumer: `compliance.audit-trail` and
      `messaging.delivery-log`. Every other module's participant data has no
      retention rule and nothing listening — walk them once (Admission's
      check-ins and Registration's registrant read model first) and decide
      the numbers with the same care D-CPL-01 got.
- [ ] Authorization stays capability gates in the controllers
      (`Gate::authorize(Permission::X->value)`), which is what every other
      module does — `src/Policies/` is still empty and would only pay off for
      per-row decisions, which no Compliance row has. Revisit if one appears.
- [x] Consent *withdrawal* as a request type: `ConsentWithdrawal` in
      `DataSubjectRequestType` with `PolicyVersion::lawful_basis` declared
      (ADR-0050) to route to self-service or operator review. The operator
      console handles it with the same deadline watch, audit trail, and
      refusal machinery. Completed in slice 20.
- [ ] The consent gate is opt-in per route (`require-consent`). Registration's
      public form carries it and the two public checkouts now carry their own
      declaration (#2). Two purchase-adjacent routes still take neither:
      `registration.waitlist.claim` (POST /waitlist/{entry}/claim) and
      `admission.claim` (POST /admissions/claim/{link}) — both `auth` +
      `signed`, and claiming a waitlist offer produces a registration and an
      order. Decide those two, then walk the rest of the route list.
- [ ] Both `/pay/registration/…` routes went behind `auth` with #2, because a
      declaration that cannot be recorded is not a declaration. That closes
      the hole and postpones the question: when the account-less subject
      identity lands, revisit whether a guest may pay without an account at
      all, and if so, what a Consent row for one looks like.
- [ ] The real AGB and Widerrufsbelehrung are still placeholders, blocked on
      the imprint release (#91). A human has to read both against the seeded
      text before the first real sale, and check the § 312g Abs. 2 Nr. 9 BGB
      exemption against what the operator actually sells (a fixed-date event
      is exempt; a shipped item of merchandise is not, and the shop sells
      both — the notice at checkout currently states the event case only).
- [ ] `CheckoutConsentGiven` (#2) carries no order reference (`brandId`,
      `subjectId`, `captureContext`, `acceptedAt` only): the recorded Consent
      cannot be traced back to the order it was captured for.
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
- [ ] Content's handler anonymizes comment *authorship* and keeps the
      bodies. A person who wants their words gone needs an operator to act
      through the moderation queue — decide whether self-service deletion of
      one's own comments belongs on the privacy page.
- [ ] Consider a Postgres partial unique index
      (`policy_versions(policy_id) WHERE state = 'published'`) for
      concurrent-write safety. Today "only one published version per policy"
      is enforced solely inside `PublishPolicyVersion`'s transaction, which is
      correct for the single-writer path this Action guarantees but not
      immune to two concurrent direct writes bypassing the Action.
- [ ] Commerce's handler cannot answer for an email-only subject: `orders.billing_party`
      (JSON) carries an `email` sub-field via BillingPartyData, but guest-checkout paths
      do not populate it without an account. Commerce owes an email lookup path once a
      guest-checkout flow populates the billing_party email without requiring a user_id.
      Newsletter has no `GdprHandler` at all; it is Phase 3 and holds no data yet, but
      owes a handler before its first subscription is stored. Admission (claim links'
      `intended_email`) and Registration (the registrant read model, reachable by email
      once its account is gone) gained their own email branches in slice 20 — this item
      now names only the two modules still owing one.
- [ ] Single-use token flows (verification, archive download) check then
      consume with a race gap: `DataSubjectRequestVerification::findByToken()`
      + `->delete()` in `PublicDataRequestController::verify()`, and the
      `archive_token_hash` column consumed inline in
      `DataRequestController::downloadWithToken()`. A shared conditional-UPDATE
      pattern (`UPDATE … WHERE … RETURNING …`) would close the window a
      parallel request can exploit to burn the token twice.
- [ ] `auditEntry()` context for `SubjectRequestRefused` and
      `UserDeletionRequested` drops `subject_email` for requests filed by
      email-only subjects, because the `user_id` foreign key would fail. The
      context needs the address so the operator can see who was affected; a
      nullable `subject_email` on `audit_log_entries` or an `email_addr`
      column on those events would fix it.

## Done

- [x] `permissions:sync` exists (it shipped with the operator console) and,
      since ADR-0045, also materializes each module's default roles per
      brand.

- [x] Commerce has a GDPR handler now. ADR-0044 gave orders a
      `payer_user_id`, closing the boundary that used to leave Commerce with
      no lawful way to resolve a subject to their invoices; `CommerceGdprHandler`
      exports by that column and retains every invoice, credit note and
      payment it finds — an issued invoice is kept under §147 AO
      (Art. 17(3)(b) GDPR), so deletion is a deliberate no-op in both modes.

- [x] Subject notices and the deadline watch — the last of slice 14.
      Three subject-facing templates (export ready, deletion acknowledged,
      refusal with its legal ground) and one operator-facing escalation, all
      through Messaging's send API. No topic on any of them (ADR-0041): the
      topic machinery exists to honour preferences, and a legal notice is not
      a notification anyone may switch off — only the suppression list
      applies. All three subject notices set `logBodies`, because what we
      told a data subject and when is itself the evidence.
      **Deletion is acknowledged when it starts, not when it finishes.** By
      completion every module has scrubbed the person's contact details, so a
      completion notice would have nowhere to go; the listener is therefore
      registered *before* the fan-out, and a test asserts the mail left for
      the real address before the scrub caught up with it.
      Notices are best-effort: `SendMessage` refuses loudly when a subject
      has no resolvable address, which is right for Messaging and wrong as a
      way to fail a statutory request, so Compliance logs and continues.
      `compliance:watch-deadlines` runs daily at 06:45 and escalates twice —
      approaching (7 days) and overdue — tracked by `escalation_level` on the
      request, because a mail every morning is a mail an operator learns to
      filter. It goes to the operator's contact address through Platform's
      `OperatorSettings` doorway (Compliance may not import the Operator
      model), which gained `contactEmailForBrand()` for it.
      `tests/SubjectRequestNotificationTest.php`.

- [x] A `Gdpr/` handler in every module that holds personal data (slice 14's
      sweep): identity, admission, content, messaging, compliance, staffing,
      competition — joining registration's. Community followed with the
      slice 25 tail (profile read models, wall comments and reactions, event
      participations; `CommunityGdprHandler`'s docblock has the deletion
      order), and Identity's handler grew the profile-of-record fields with
      it (ADR-0051 §9).
      Identity's carries the hard question. An account spans the operator
      (ADR-0016) but the fan-out arrives per brand, so its handler drops the
      membership for the brand that asked and anonymizes the account **only
      when that was the last one** — a person who leaves one convention keeps
      their account for the others. Anonymization is a tombstone: the row
      survives with its ULID (`AccountState::Anonymized`, which
      `AuthenticateUser` refuses), so every `*_user_id` in every other module
      still resolves. `erase` behaves identically — a dangling reference is
      not a stronger form of privacy, it is a broken database. New
      `UserAnonymized` event (SPEC row was proposed).
      The interesting refusals are documented in the handlers themselves:
      Compliance's own deletes **nothing** (destroying consent proof to
      honour a deletion request destroys the proof it was honoured);
      Messaging keeps suppressions (they exist to keep *not* sending) while
      deleting push endpoints and inbox bodies outright; Content anonymizes
      comment authorship but keeps the bodies; Admission keeps admissions and
      scrubs only the invitation addresses it stored itself; Staffing and
      Competition export but have nothing to scrub, and say so rather than
      staying silent.
      `tests/GdprSweepTest.php` proves the whole fan-out end to end: every
      module contributes to one archive, and a deletion leaves every foreign
      reference resolvable — since slice 25 including Community's rows and
      the `username` tombstone.

- [x] Retention rules, their clocks, and the daily sweep (D-CPL-01).
      `RetentionRule` names a *data category*, never a table: Compliance
      publishes how long something may be kept and the module that holds it
      deletes its own rows when `RetentionPeriodElapsed` arrives. A sweep
      that reached into foreign tables would be the same violation the GDPR
      fan-out was built to avoid (ADR-0004) — so Compliance discharges its
      own audit-trail rule through the identical contract, as a listener like
      anyone else.
      The two D-CPL-01 numbers ship as seeded operator-wide rules (audit
      trail 36 months, delivery log 12): a platform that has to be configured
      before it is lawful is not lawful. `brand_id` is nullable for exactly
      that scope, which is why the unique constraint is hand-written as
      `UNIQUE NULLS NOT DISTINCT` — a plain unique index treats every NULL as
      distinct and would leave the operator-wide rows unconstrained.
      Two clocks: `record_age` counts each row's own age, `event_concluded`
      counts from the end of the event the data belongs to —
      `Listeners\StartRetentionClock` records that date from Event's
      `EventConcluded` rather than asking Event for it later. Event-scoped
      signals carry the event id, so a consumer knows *which* event's data is
      out of retention (SPEC payload extended accordingly).
      `compliance:apply-retention` runs daily at 03:30 with a `--dry-run`,
      and additionally deletes expired subject-export archives — files, not
      rows, and a second copy of a person's whole record that had no reason
      to outlive its download window. Messaging prunes its own delivery log
      from the same signal (`Listeners\PruneDeliveryLog`), leaving messages
      still in flight alone. Console at `/admin/retention` shows each rule's
      *current cutoff*, because "36 months" and "everything before
      2023-08-17" are the same fact and only one is checkable at a glance.
      `tests/RetentionTest.php`.

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
