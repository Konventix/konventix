# Compliance — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

Compliance owns the platform's legal-obligation surface: which policies exist in
which version, what a person agreed to and when, what privileged actions were
taken, how long data may be kept, and how a data subject request is carried out.
It absorbs LanCore's Policy and DataLifecycle domains. Its defining property is
that it orchestrates without owning: Compliance holds no personal data belonging
to another module and never queries another module's tables. A subject request
fans out as events to every module's `Gdpr/` handler, and Compliance collects
what comes back (ADR-0004).

## Owns

- **Policies and their versions**, per brand *or per operator*: terms of
  participation, privacy policy, house rules, photo consent, withdrawal
  information. A policy with a null `brand_id` belongs to the operator and
  every brand inherits it unless it publishes its own under the same slug
  (#76, ADR-0016). Each version has an effective date, published text, and a
  flag for whether agreement is required.
- **Policy bindings** (ADR-0066, D-CPL-02/03/04/05): what a policy governs —
  a `kind` (`event`, `registration_type`, `settlement_provider`,
  `entry_condition`) and a `target_key`, which is a ULID for the three entity
  kinds and the provider's own key for the fourth. A binding hangs on the
  **policy**, never on a version: rebinding a text is not a new version of
  it. `brand_id` is nullable and mirrors the policy's: a brand may bind the
  operator's inherited text to its own event, the operator binds globally
  with null. **A policy with no bindings applies everywhere its brand
  sells** — today's behaviour, unchanged, and the state the console states
  out loud.
- **The resolver** (`Bindings\PolicyResolver`, ADR-0066 D-CPL-03/08): which
  texts apply to *this* act, given a `BindingContext` of scalars the calling
  module builds and never interprets. A bound policy matches when, for every
  kind it binds on, one of its bindings of that kind matches — **OR within a
  kind, AND across kinds**; a kind it does not bind on places no requirement,
  and a bound kind the context carries nothing for cannot match.
  `requires_consent` alone decides agreement versus information; there is no
  role column and no third role. `Support\CheckoutLegalTexts::forBrand()`
  stays the no-context call the #222 gap notice asks.
- **The consent controls on every purchase path** (ADR-0066 §6.2, §6.3):
  one builder, `Support\ConsentContribution`, fills both slots —
  Commerce's `checkout.consent` and Registration's own
  `registration.consent` — from a `BindingContext`. What crosses the
  boundary is unchanged in kind: a boolean, a URL and two lists of names and
  links (`policies` to agree to, `notices` to read, split by
  `requires_consent`). Version ids stay on the server; which version an
  agreement binds is resolved again when it is recorded. The asking module
  still never learns that a policy exists (T-2).
- **The console's binding editor** (§9.2): the *Where this applies* card on
  the policy editor, behind `ManagePolicyBindings`. Bindings as chips
  grouped by kind, a picker fed by Platform's `Targets\TargetDirectory`
  (Compliance reads no foreign table, D-CPL-11), the empty-state sentence
  *"No bindings: this text applies everywhere this brand sells"*, and — once
  a second kind is bound — the matching rule in words. A target the registry
  cannot name renders as `Unknown (event · 01HX…)` with *Remove*: a dangling
  binding never matches and is not an error. The policy index marks a bound
  text.
- **The record behind the two-step checkout** (D-CPL-07):
  `RecordCheckoutConsent` and `RecordConsentDeclaration` resolve the context
  their surface rendered — carried on `CheckoutConsentGiven` and
  `RegistrationConsentGiven` — and never re-derive a list from the brand.
  That is what keeps the rows and the page the payer saw identical.
- **Consent records**: subject, policy version, timestamp, the context the
  consent was captured in, the binding that made the text applicable and a
  snapshot of the scalars the resolution ran on (D-CPL-09 — evidence, never
  a read model; both null for an unbound policy and for every row written
  before ADR-0066), and withdrawal. A subject is a ULID that may be a
  user or — for people who registered without an account — a subject key issued
  by Compliance.
- **The login and checkout re-consent gate** (#205): a narrower question
  than the `require-consent` route alias — `OutstandingConsents::
  subjectHasOutstandingContract()` adds `lawful_basis = contract` to the
  required-version query. A newly published contract version is asked for
  once, at the next login or checkout, and the consent row written for
  that version id silences both. A fresh registration and a remember-me
  recall are **not** gated by this. The login gate's answer crosses to
  Identity as one session key, `compliance.consent_redirect`, written by
  `Listeners\FlagOutstandingContractConsent` on Laravel's `Login` event;
  Identity reads the key by name and never imports Compliance (ADR-0004).
  `konventix:install` seeds three operator-wide placeholder policies —
  `terms`, `withdrawal` and `privacy` — and publishes each one. **None of
  them requires consent**: a placeholder that binds nobody must never
  raise this gate, since the install runs on every release and a
  consent-requiring placeholder would redirect every existing account on
  every brand to `policies.required`. Publishing the operator's real AGB
  with `requires_consent` is what raises the gate, once, for a text that
  deserves it (#91, #200).
- **The data subject request workflow**: a request entity with a type (export,
  deletion, rectification), a state (received, in progress, fulfilled, refused),
  the statutory deadline, and the record of which modules have responded.
- **The GDPR handler registry**: every module registers its export and
  anonymization handler from its own ServiceProvider (ADR-0003); Compliance
  addresses them by event and never by direct model access.
- **Export assembly and delivery**: collecting handler contributions into one
  archive, its download link, and the expiry of that archive.
- **The audit trail**: an append-only record of privileged actions across brands
  — actor ULID, action, subject ULID, brand ULID, timestamp — built by
  subscribing to other modules' domain events rather than by modules calling
  into Compliance.
- **Retention rules and the data lifecycle**: how long each category of data may
  be kept, when a clock starts (usually `EventConcluded`), and the scheduled
  signal that a retention period has elapsed. Compliance publishes the rule;
  the owning module deletes its own rows.

## Does not own

| Nearby concept | Owned by |
|---|---|
| The personal data itself, in every category | the module that collected it, each exposing a `Gdpr/` handler (ADR-0004) |
| User accounts and the mechanics of anonymizing one | Identity |
| Roles, permission grants, and the capability catalogues being audited | Platform for role data, each module for its own enum (ADR-0013) |
| Rendering the privacy or imprint page | Platform's block CMS — Compliance owns the policy version behind it |
| Sending "your export is ready" or a policy-change notice | Messaging |
| Marketing consent as a subscription state, unsubscribe handling | Newsletter and Messaging — Compliance holds the consent record, they decide deliverability |
| Invoice retention under German bookkeeping law | Commerce, which enforces it on its own invoices; Compliance owns the rule that forbids their early deletion |
| Access control decisions at request time | Laravel's Gate, through the owning module's policies |
| Security incident response, logging infrastructure, backups | operations, not this module |

The line to hold: Compliance is the module that knows *what the obligation is*.
The module that holds the data is the one that discharges it.

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

A row becomes binding when its event class lands; the rest stay proposals.

| Event | Emitted when | Payload | Status |
|---|---|---|---|
| `PolicyPublished` | A policy version becomes effective | `policy_id`, `version_id`, `brand_id` (null for an operator-wide text, #76), `slug`, `effective_at`, `requires_consent` | shipped |
| `PolicyWithdrawn` | A policy version is superseded or withdrawn | `policy_id`, `version_id`, `brand_id` | proposed |
| `ConsentGranted` | A subject agrees to a policy version | `consent_id`, `subject_id`, `policy_id`, `version_id`, `brand_id`, `granted_at`, `capture_context` | shipped |
| `ConsentWithdrawn` | A subject withdraws agreement | `consent_id`, `subject_id`, `policy_id`, `withdrawn_at`, `brand_id` (null where none could be named) | shipped |
| `ConsentPromptRaised` | A subject is *asked* to agree to something | `brand_id`, `subject_id`, `surface` (`login`/`checkout`/`registration`/`route_gate`), `reason` (`contract`/`required`) | shipped |
| `PolicyBound` | A policy is bound to something it governs | `policy_id`, `binding_id`, `brand_id` (null when the operator binds globally), `kind`, `target_key`, `changed_by_user_id` | shipped |
| `PolicyBindingRemoved` | A binding is taken off a policy | same payload as `PolicyBound` | shipped |
| `DataExportRequested` | An export request is accepted and fans out | `request_id`, `subject_id`, `brand_id`, `requested_at` | shipped |
| `DataExportCompleted` | Every handler has responded and the archive exists | `request_id`, `subject_id`, `archive_expires_at` | shipped |
| `UserDeletionRequested` | A deletion request is accepted and fans out | `request_id`, `subject_id`, `brand_id`, `mode` (`anonymize` or `erase`) | shipped |
| `UserDeletionCompleted` | Every handler has confirmed | `request_id`, `subject_id`, `completed_at` | shipped |
| `SubjectRequestRefused` | A request is refused, with a legal ground | `request_id`, `subject_id`, `reason_code` | shipped |
| `RetentionPeriodElapsed` | A retention clock runs out for a data category | `rule_id`, `brand_id`, `data_category`, `cutoff_at`, `event_id` (event-scoped clocks only) | shipped |

Handlers must be idempotent: a request may be re-dispatched after a partial
failure, and Compliance re-runs the fan-out rather than tracking which rows a
module already touched.

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `UserRegistered` | Identity | Open a consent state for the new subject and record which policy versions were in force |
| `UserAnonymized` | Identity | Mark Identity's part of a deletion request fulfilled |
| `EventConcluded` | Event | Start the retention clocks tied to that event's data |
| `BrandCreated` | Platform | Seed the brand's required policy set |
| every event marked auditable | all modules | Append to the audit trail — a wildcard subscription over events carrying an auditable marker, so modules never call into Compliance directly |

Compliance keeps a minimal subject read model — subject ULID, brand, and
contactability — and nothing else. It never mirrors another module's records;
the whole point of the fan-out is that it does not have to.

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

Proposed catalogue: `ViewPolicies`, `ManagePolicies`, `PublishPolicy`,
`ManagePolicyBindings`, `ViewConsents`, `ViewSubjectRequests`,
`HandleSubjectRequests`, `DownloadSubjectExport`, `ViewAuditLog`,
`ManageRetentionRules`. Downloading a subject export is separated from
handling requests on purpose: seeing that a request exists and seeing its
contents are different levels of access. `ManagePolicyBindings` is separate
from `ManagePolicies` for the same kind of reason (D-CPL-16): binding a text
to a product changes who is bound by it without changing a word of it.
Compliance ships no default role of its own, so both reach an operator
through Platform's `platform.brand-admin` (ADR-0045).

## Observability

`Metrics\ComplianceMetrics` (ADR-0059, ADR-0066 §10):
`consent_prompts_total{brand, surface, reason}`,
`consent_grants_total{brand, surface}`,
`consent_withdrawals_total{brand}`,
`policy_binding_matches_total{brand, kind}` — written by the resolver, since
a resolution is not an event — and the scrape-time gauge
`outstanding_contract_subjects{brand}`. Every label is a bounded set; a
policy slug or a binding target never becomes one. The **refusal rate is
derived** from prompts against grants on the dashboard's *Consent and
conditions* row (D-CPL-17); there is no refusal counter, and the
interstitial's "Not now" increments nothing. Alert:
`KonventixConsentGateBlocking`.

## Activation

Kernel module. Always active for every brand and not gateable: its provider sets
`$gateable = false` and its routes carry no `ensure-module` middleware. Legal
obligations are not a feature a brand opts into, and a deactivated Compliance
would silently break the fan-out that every other module's `Gdpr/` handler
depends on. Which policies a brand publishes is data; whether the module runs is
not a question.

## Open questions

- ~~Is the audit trail built by wildcard subscription over events carrying an
  auditable marker, or by an explicit recorder contract modules call? ADR-0004
  pushes hard towards the former, but actions with no natural domain event
  (viewing a participant list, exporting a CSV) have nothing to subscribe to.~~
  **Decision log Round 6 (D-BATCH):** a hybrid — wildcard subscription over
  auditable-marked events for the common case, plus an explicit recorder
  contract for eventless actions (viewing a list, exporting a CSV) that have
  nothing to subscribe to.
- What identity does an anonymous subject use — someone who registered without
  an account — and how do they authenticate a subject request against it?
- ~~Is `anonymize` or `erase` the default deletion mode, and what minimum does
  a module keep when bookkeeping law conflicts with an erasure request?~~
  **Decision log Round 6 (D-BATCH):** anonymize by default, with a tombstone
  row surviving so foreign ULID references stay resolvable — mirrors
  Identity's own settled anonymization question.
- ~~One person exists across several of the operator's brands. Is a subject
  request scoped to the brand it was filed on, or does it span the operator?
  The account half is settled (ADR-0016: one account per operator); the
  request scope itself is still to decide.~~ **Decision log Round 6
  (D-BATCH):** a subject request spans the operator, consistent with
  ADR-0016's global account scope.
- ~~Who watches the statutory deadline and escalates — a Compliance scheduler
  emitting reminders through Messaging, or an operator-facing queue only?~~
  **Decision log Round 6 (D-BATCH):** a Compliance scheduler watches the DSR
  deadline and escalates through Messaging.
- ~~How far back the audit trail is retained, given that the audit trail is
  itself personal data subject to retention rules. **Still open** after the
  Round 6 gate review — pairs with Messaging's own open delivery-log
  retention question.~~ **Decision log Round 7 (D-CPL-01):**
  Compliance-published rules — audit trail 36 months, delivery log 12 months,
  enforced through the retention machinery.
