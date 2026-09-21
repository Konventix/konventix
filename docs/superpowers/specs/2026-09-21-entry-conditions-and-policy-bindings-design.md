# Entry conditions, policy bindings, and re-consent to a contract

Date: 2026-09-21. Status: **answered and settled**. The operator answered every numbered item of §14 on 2026-09-21; §14 now records those answers, §13 carries them as decisions, and the body of this document states them as facts. Implementation slices: `docs/superpowers/plans/2026-09-21-round-4.md`, Tasks 30–33 (build order §16).
Decision: **D-REG-16** (`docs/inventory/decision-log.md`, Round 9, 2026-09-21): *"Designed now and built directly on develop, no issues: one spec plus ADR for entry conditions on registration types and policies bound to an event, a type, a payment provider or an entry condition, and re-consent to contract policies on login or before critical actions."*
Answers: decision log Round 10 (`docs/inventory/decision-log.md`, 2026-09-21) — D-REG-17…24, D-ADM-06/07, D-CPL-02…17.
Companion ADR: `docs/decisions/0066-policies-bind-to-what-they-govern.md` (**accepted**, 2026-09-21).
Neighbours: #76 (operator-wide policies), #200 (the two checkout roles), #205 (the login and checkout contract gate, shipped), #2 (checkout consent, shipped), #184 (`PublicLegalTexts`), #222 (warn, don't refuse, on a missing checkout text).
Decisions this rests on: ADR-0003 (self-registration, four gate points), ADR-0004 (events are the only contract), ADR-0005 (the registration-type axes), ADR-0009 (one `Data` per endpoint), ADR-0013 (capabilities are enums), ADR-0014 (ULIDs, no cross-module FKs), ADR-0016 (one account per operator), ADR-0050 (a subject spans the operator), ADR-0055 (WCAG 2.1 AA), ADR-0059 (metric, panel, alert), ADR-0060 (a type's terms freeze once it sells).

## 1. Goal

Three asks from the operator, one design, because they are the same sentence said three ways: **a legal text should apply to exactly what it governs, and be asked for exactly when it binds.**

1. A `RegistrationType` may declare **entry conditions** — "Student ticket", "Azubi ticket", "Member of the association". Someone registering on such a type *asserts* they meet the condition. A condition is a first-class row with a name, the sentence the registrant ticks, and how it is verified (self-declared, document at the door, operator-checked), not a word in the type's name and not a free-text custom field.
2. A **policy may be bound** to an event, a registration type, a settlement provider (`stripe` / `mollie` / `unzer` / `manual`) or an entry condition. A bound policy applies — and is asked for — only where the binding matches the registration or the checkout at hand. Today every policy is brand-wide or operator-wide and the checkout knows exactly two roles, `terms` and `withdrawal`.
3. A policy that is a **contract** re-asks for agreement when a new version is outstanding: at login, and in a running session before the acts that create or change a contract. Today `require-consent` is an opt-in route alias and the contract gate covers login and checkout only.

Nothing here changes what a policy *is*. A policy still belongs to a brand or to the operator, still has versions, still records consent against a version id. What changes is that a policy may now say *what it governs*, and that Registration gains a vocabulary for what a person asserted when they bought a reduced ticket.

## 2. Facts the design rests on

Verified 2026-09-21 on `develop` (9211521, after release 0.7.0).

- **Policies.** `policies` = `id`, `brand_id` (**nullable** since #76 — null means the operator's, inherited by every brand), `slug`, `name`, unique `(brand_id, slug)`. `policy_versions` = `policy_id`, `version`, `content`, `requires_consent` (default true), `state` (`draft|published|superseded`), `effective_at`, `published_at`, `lawful_basis` (`consent|contract|legitimate_interest`, added 2026-09-01). `Policy::resolveFor($brandId, $slug)` is the one resolution: the brand's own row shadows the operator's, *structurally, not publication-aware*. `PublishPolicyVersion` keeps at most one published effective version per policy.
- **Consents.** `consents` = `brand_id`, `subject_id` (a user ULID today; a Compliance-issued subject key later — no FK by design), `policy_version_id` (in-module FK), `granted_at`, `capture_context` (a string, e.g. `checkout`, `consent_form`), `withdrawn_at`. `GrantConsent` is idempotent per (subject, live version).
- **The checkout consent path (#2, #200).** `CheckoutLegalTexts::SLUGS = ['terms', 'withdrawal']`, `forBrand($brandId)` returns name + root-relative URL + version id per slug, `EXEMPTION_NOTICE` is the § 312g Abs. 2 Nr. 9 BGB sentence and is deliberately *not* a policy. `CheckoutLegalTextRoleData` says what each slug means and whether the payer *agrees* (`terms`) or is only *informed* (`withdrawal`). Compliance contributes `compliance/CheckoutConsent` to the Platform UI slot **`checkout.consent`**; Commerce reads it through `CheckoutConsentSlots` and learns two facts only — `required: bool` and `outstandingUrl: ?string`. Commerce never learns that a policy exists.
- **`SlotRegistry::for($slot, array $context = [])` already resolves closure props against a context bag.** `CheckoutConsentSlots::contributions()` passes **no** context today. That parameter is the seam this design uses.
- **The checkout POST carries provider and declaration together.** `CheckoutController::start()` validates `['account' => ulid, 'accept_terms' => accepted]` in one request, emits `CheckoutConsentGiven(brandId, subjectId, captureContext: 'checkout', acceptedAt)` *before* redirecting to the PSP, then resolves the adapter. So the provider **is** known at the moment the declaration is recorded — but it is **not** known when the page renders the text list. `CartController` and `CheckoutController::startOrder()` emit the same event.
- **`RecordCheckoutConsent`** re-resolves `CheckoutLegalTexts::forBrand()` at listener time and writes one consent row per resolved published version. It records nothing where nothing is published.
- **The contract gate (#205) is half-shipped.** `OutstandingConsents::subjectHasOutstandingContract()` = published + effective + `requires_consent` + `lawful_basis = contract` + no un-withdrawn consent, over `Policy::resolvedFor($brandId)`. `FlagOutstandingContractConsent` listens on Laravel's `Login` and writes the single session key `compliance.consent_redirect`; Identity's Fortify responses and `SocialAuthController` read it by name (`RedirectsOutstandingContractConsent`) and re-write `url.intended`. The checkout slot raises `outstandingUrl`. The interstitial is `compliance/pages/policies/Required.vue` at `policies.required`; `ConsentController::store()` ends with `redirect()->intended('/')`.
- **`require-consent`** is `EnsurePoliciesAccepted`, aliased by Compliance's provider, applied by the owning module (ADR-0003). It asks the *wider* `subjectHasOutstanding()` (any required text, any basis), passes guests through, stores `url.intended` on GET only, redirects to `policies.required`. Consumers today: Registration's public form (`registration.show`/`store`, beside `ensure-waiting-room`) and Identity's social-callback sign-up group. Deliberately **not** on `/my/registrations`, `/my/orders` or the policy pages themselves — a gate in front of the record of your own contract is a lockout, and one in front of the policy page is a loop.
- **`konventix:install`** seeds three operator-wide placeholders — `terms`, `withdrawal`, `privacy` — published and **none requiring consent**, precisely so the install cannot redirect every account on every brand to `policies.required`.
- **Registration types.** `registration_types` = `event_id`, `name`, `visibility`, `approval`, `settlement`, `confirmation_gate`, `grants` (json), `price_declaration`, `terms_days`, `sales_start`/`sales_end`, `max_quantity_per_registration`. `GuardsTypeConfiguration::frozenAxes()` freezes `eventId`, `approval`, `settlement`, `confirmationGate`, `termsDays`, `grants` and `priceDeclaration` the moment one registration exists (ADR-0060); only label, visibility and quota links stay editable.
- **Registrations.** `registrant_user_id` is required (D-REG-08 — accounts in Phase 1); `quantity` was added 2026-09-21 and multiplies grants exactly once, in `ConfirmRegistration`. `SubmitRegistration` guards per type what a static ruleset cannot see (`guardBillingParty()`, the quantity ceiling) — the precedent this design copies. The public form is `registration/Register.vue`, props are `RegistrationFormData`, the POST is `SubmitRegistrationData`.
- **Admission.** `AdmissionIssued` carries role, holder, buyer. The door runs an **offline-capable client** against a **PII-free delta feed** and an `EntranceEventProfile` read model (ADR-0002, ADR-0033); `Checkpoint` "carries no policy". `AdmissionCheckedIn` carries `check_in_id`, `admission_id`, `registration_id`, `event_day_id`, `checkpoint_id`, `direction`, `scanned_at`.
- **Commerce settlement.** `settlement_accounts.provider` is a **string(32) key**, not an enum and not a ULID — one live account per `(brand, provider)`; `ProviderLabels::labelFor()` knows `stripe|mollie|unzer|manual|null`. A provider is therefore addressed by its key, and the same key appears on every `Payment`.
- **Platform seams that exist.** `Platform\Legal\PublicLegalTexts` (#184): Platform declares the interface and binds a null default, Compliance binds `PolicyPublicLegalTexts`, Platform's own `OperatorPresence` resolves it — the precedent for "a kernel-declared contract that Compliance fills". `SlotRegistry`, `NavigationRegistry`, `BlockRegistry` are the precedent for "modules contribute, the reader never knows who".
- **Compliance has no `src/Metrics/`** — this feature is its first (ADR-0059: `src/Metrics/<Module>Metrics.php`, recorders in `src/Listeners/Metrics/`, a panel in `contrib/observability/src/*.mjs`, a rule with a unit test, a runbook anchor; `ObservabilityCoverageTest` enforces the mechanical half).
- **No i18n library.** UI copy is literal English; mail templates carry `de`/`en` through Messaging's `TemplateRegistry`. A policy's *content* is the operator's own text, in whatever language they wrote it.

## 3. Architecture

```
REGISTRATION                                   COMPLIANCE
 entry_conditions  ─────┐                       policies ── policy_versions
   key,name,text,       │                          │
   verification         │                       policy_bindings
 registration_type_     │                          policy_id + kind + target_key
   entry_condition      │                          kind ∈ event | registration_type
 entry_condition_       │                                 | settlement_provider | entry_condition
   assertions           │                          │
   per registration     │                       PolicyResolver::forContext(BindingContext)
        │               │                          │  unbound policies + bindings that match
        │               └──── slot context ───────►│
        │        registration.consent  {eventId, typeId, conditionIds}
        │        checkout.consent      {… + provider, orderId}
        ▼                                          ▼
 RegistrationConsentGiven ────────────────► RecordConsentDeclaration
 CheckoutConsentGiven (Commerce) ─────────► one consents row per matched version,
                                             carrying binding_id + context snapshot
 RegistrationConfirmed.entryConditionKeys
        │
        ▼
 ADMISSION  admissions.entry_conditions (key+label) ──► door: "check Student ID"
        │                                                        │
        └────────── AdmissionCheckedIn.conditionsChecked ◄────────┘
                    → Registration stamps the assertion verified

RE-CONSENT   Login ──► FlagOutstandingContractConsent (shipped)
             critical route ──► require-contract-consent (new alias, applied by the owning module)
             both ──► policies.required, url.intended preserved
```

Three rules hold the whole thing together, and every decision in §14 is measured against them:

- **Compliance alone resolves policies.** Commerce, Registration and Identity hand it *scalars* and get back a rendered contribution or a boolean. No module outside Compliance ever learns that a `Policy` row exists (ADR-0004; `tests/Unit/ArchitectureTest.php` enforces it).
- **Registration alone owns entry conditions.** Compliance stores an entry-condition **ULID** as a binding target and never resolves its name from Registration's tables; the console gets labels from a registry modules contribute to (§7.3).
- **Over-binding over-asks.** A resolution that matches too much shows a payer one text too many. A resolution that matches too little means an unenforced legal requirement. Every ambiguity in this design is therefore resolved in the direction of asking.

## 4. Domain model — Registration: entry conditions

All ids ULIDs (ADR-0014); cross-module references are bare `ulid` columns with no FK; in-module FKs are real.

### 4.1 `registration_entry_conditions`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid PK | Load-bearing outside the module: it is Compliance's `target_key` for a `entry_condition` binding and (as the `key` below) the door's label id. |
| `brand_id` | ulid, indexed | `BelongsToBrand` (ADR-0010). |
| `event_id` | ulid, nullable | Null = reusable across every event of the brand, which is the normal case; a value pins a one-off condition to a single event (D-REG-17). |
| `key` | string(40) | Stable, slug-cased (`student`, `azubi`, `member`). Unique per `(brand_id, key)`. This — not the ULID — is what travels to the door and into metric-free log lines. |
| `name` | string | "Student ticket". Shown in the console, on the door's scan result and on the ticket PDF — never on the badge (D-ADM-07). |
| `description` | text, nullable | Operator-facing: what this condition means, for whoever edits a type later. |
| `assertion_text` | text | **The sentence the registrant ticks**, verbatim. "I confirm that I am enrolled as a student and will present a valid student ID at the door." |
| `document_hint` | string, nullable | "Valid student ID or enrolment certificate." Rendered under the checkbox and printed beside the condition on the ticket. |
| `verification` | string(32) | `self_declared` \| `document_at_door` \| `operator_checked` — these three kinds and no fourth (D-REG-22). `operator_checked` is **advisory**: it fills an operator worklist and never gates confirmation; an operator who wants a blocking check sets `approval: required` on the type, and the condition editor says so. |
| `archived_at` | timestamp, nullable | Archived conditions stay attached to past registrations and disappear from the type editor. |
| timestamps | | |

### 4.2 `registration_type_entry_condition`

Pivot, `(registration_type_id, entry_condition_id)` unique, both in-module FKs (D-REG-18). A condition is reusable across types (one "Student" condition, four student types); a type may declare zero, one or several. Ordering column `position` so the form renders them in a chosen order.

**Changing a live type (D-REG-19).** `entryConditions` does **not** join `GuardsTypeConfiguration::frozenAxes()`: the set of conditions stays editable after the type has sold, like the label and the visibility. The evidence is the snapshot on the assertion, not the pivot, so existing registrations are never rewritten:

- **Existing registrations keep their assertion snapshots.** They asserted what they were shown, with the wording they were shown, and nothing retroactive is written for or against them.
- **A condition added to a live type is "not asserted" for every registration that already exists.** No assertion row is created. The registration detail lists it as *Not asserted — added after this registration*, and the operator may *Waive* it (a `waived` assertion carrying `reason_code: operator_waived`) or ask the registrant by hand. It is not an error state and it does not block confirmation, check-in or a refund.
- **A condition removed from a live type keeps its historic assertions.** The rows stay on the registrations that made them, stay in the GDPR export, and are shown on the registration detail as *No longer required by this type*. The condition itself is archived, never deleted — the assertion FK restricts.
- **Admissions carry what they were stamped with.** An admission issued before the change keeps its stamped condition list; the new label reaches only admissions issued afterwards (§4.5). The door is never handed a check the ticket holder was never told about.
- The type editor **warns before saving** a change on a type that has registrations, naming how many registrations will show a *not asserted* condition, or how many historic assertions a removal leaves behind.

**Alternative type (D-REG-21).** `registration_types` gains a nullable `alternative_registration_type_id` (same event, in-module FK, nullable). It is used for exactly one thing: the "Buy the regular ticket instead" link shown beside a refused submission (§6.2). It is not an axis, it grants nothing, and it may be set and cleared at any time.

### 4.3 `registration_entry_condition_assertions`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid PK | |
| `brand_id` | ulid, indexed | |
| `registration_id` | in-module FK | Cascade on delete with the registration. |
| `entry_condition_id` | in-module FK | Restrict: a condition with assertions is archived, never deleted. |
| `subject_user_id` | ulid | Who asserted — the registrant (`registrations.registrant_user_id`); no FK, Identity's (ADR-0014). |
| `asserted_at` | timestamp | |
| `assertion_text_snapshot` | text | The wording actually shown. The condition's text may be reworded later; the evidence may not change under it. This is the same principle as consenting to a *version*. |
| `verification_snapshot` | string(32) | How it was to be verified when it was asserted. |
| `state` | string(24) | `asserted` \| `verified` \| `refused` \| `waived`. |
| `decided_by_user_id` | ulid, nullable | Operator or door staff who verified/refused/waived. |
| `decided_at` | timestamp, nullable | |
| `decided_via` | string(16), nullable | `console` \| `door`. |
| `reason_code` | string(40), nullable | Bounded codes (`document_missing`, `document_invalid`, `operator_waived`), never free text about a person. |
| timestamps | | Unique `(registration_id, entry_condition_id)`. |

**Quantity (D-REG-20).** One assertion per `(registration, condition)` regardless of `registrations.quantity`: the registrant asserts once, for the contractual act. The *door* checks per person because every admission carries the label. Per-holder declarations, collected from each holder on a claim link, are the honest follow-up (§15) and are not part of this build.

### 4.4 Enums (Registration)

`EntryConditionVerification: self_declared|document_at_door|operator_checked`, `EntryConditionAssertionState: asserted|verified|refused|waived`, `EntryConditionDecisionSource: console|door`, `EntryConditionReasonCode: document_missing|document_invalid|operator_waived` (bounded, never free text about a person).

### 4.5 Admission

`admissions.entry_conditions` — a JSON list of `{key, label, verification, document_hint}` stamped at issuance from `RegistrationConfirmed`, and nothing else. Admission stores it as opaque scalars and serves it in the entrance payload so the offline door can say "Check: Student ID" beside the green tick. Admission never reads a Registration table and never learns that an assertion row exists.

Where it is visible is settled (D-ADM-07): **the door and the operator console see the label; the badge does not carry it**, because it is a fact about a fare and not something to be worn around a neck all weekend. The **document hint** prints on the **ticket PDF** (`TicketDocumentRenderer`), so nobody arrives without what the door will ask for. Badge templates and `BadgeDocumentRenderer` are untouched by this feature.

## 5. Domain model — Compliance: policy bindings

### 5.1 `policy_bindings`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid PK | |
| `brand_id` | ulid, **nullable** | Mirrors `policies.brand_id` through `MayBeOperatorWide`: a binding on an operator-wide policy carries null and is inherited, a binding on a brand's own policy carries the brand. A brand may bind the operator's text to its own event; the operator binds globally with null (D-CPL-05). |
| `policy_id` | in-module FK | Cascade with the policy. Bindings hang on the **policy**, not the version: rebinding a text is not a new version of it. |
| `kind` | string(32) | `event` \| `registration_type` \| `settlement_provider` \| `entry_condition`. |
| `target_key` | string(64) | A ULID for the first three entity kinds, the provider **key** (`stripe`, `mollie`, `unzer`, `manual`) for `settlement_provider`. One column, because a provider is not an entity and a fifth kind must not cost a migration (D-CPL-02); the console does the type-checking the database is not doing. |
| `created_by_user_id` | ulid, nullable | Who bound it; the change is also an audit row. |
| timestamps | | Unique `(policy_id, kind, target_key)`. Index `(kind, target_key)` — the resolver's query. |

### 5.2 What a binding means

- **A policy with no bindings behaves exactly as it does today** (D-CPL-04): brand-wide (or operator-wide and inherited), applicable to everything, asked for wherever its slug plays a role. Nothing in the existing install, the three seeded placeholders, or #200's two roles changes. The console states this out loud on the binding card, because the empty state is the meaningful one.
- **A policy with one or more bindings applies only where its bindings match the context at hand**, combining as **OR within a kind and AND across kinds** (D-CPL-03): several events are alternatives, but a policy bound to an event *and* to a provider applies only where both hold. "Our Stripe addendum, but only at the big event" is therefore expressible directly, with two bindings on one policy — which is exactly why the operator chose this combination over a flat OR.
- A binding whose target no longer exists (a deleted type, a disconnected provider) simply never matches. It is not an error; it is a console warning (§9.2).

### 5.3 The binding context

The one value every consumer hands Compliance. Scalars only, built by whichever module owns the surface, never interpreted by that module:

```php
final readonly class BindingContext   // Konventix\Compliance\Bindings\BindingContext
{
    public function __construct(
        public string $brandId,
        public ?string $eventId = null,
        /** @var list<string> */ public array $registrationTypeIds = [],
        /** @var list<string> */ public array $entryConditionIds = [],
        public ?string $settlementProvider = null,
        /** the act this resolution belongs to, for the evidence snapshot */
        public ?string $registrationId = null,
        public ?string $orderId = null,
    ) {}
}
```

### 5.4 The resolver

```php
final class PolicyResolver    // Konventix\Compliance\Bindings\PolicyResolver
{
    /** @return list<ResolvedLegalText> slug, name, url, versionId, version, requiresConsent, scope, role, bindingIds */
    public function textsFor(BindingContext $context): array;

    public function outstandingContractFor(string $subjectId, BindingContext $context): ?string;
}
```

`textsFor()` is `CheckoutLegalTexts::forBrand()` generalised: it resolves the brand's policy set (`Policy::resolvedFor`, which already handles the operator-wide shadowing), keeps every **unbound** policy, adds every **bound** policy whose bindings match the context, drops anything without a published, effective version, and returns them with the bindings that matched. `CheckoutLegalTexts::forBrand()` stays as the no-context call — it is what `missingSlugsForBrands()` and the #222 gap notice ask, and those questions are still brand-wide.

**The matching rule (D-CPL-03).** A bound policy matches when, *for every kind it binds on*, at least one of its bindings of that kind matches the context — **OR within a kind, AND across kinds**. A kind the policy does not bind on places no requirement. A kind it *does* bind on for which the context carries nothing — no provider chosen, no event at a login — cannot match, and the text is not shown there. The sentence the resolver implements and the console prints is one and the same: *"applies when any of the events match and any of the types match and any of the conditions match and any of the providers match."*

**What comes back, and in what order (D-CPL-08).** Every matching text is returned; the version's `requires_consent` decides whether the surface renders a checkbox ("I agree") or a notice ("please read"). There is no role column and no third role: the `terms` and `withdrawal` slugs keep their `CheckoutLegalTextRoleData` description and are listed first, in that order, and every other matching text follows, sorted by name. `outstandingContractFor()` asks the same resolution narrowed to `lawful_basis = contract`.

### 5.5 Consent records carry the binding

`consents` gains two nullable columns:

- `policy_binding_id` — the binding that made this text applicable, null for an unbound policy.
- `binding_context` (json) — the scalars the resolution ran on: `event_id`, `registration_type_ids`, `entry_condition_ids`, `settlement_provider`, `registration_id`, `order_id`. Evidence, not a read model: it is never queried to find registrations, only read back on a single consent row to answer "why was this person asked, and for which purchase" (D-CPL-09).

`capture_context` keeps its meaning (`checkout`, `registration`, `consent_form`, `login`) and stays the coarse label.

## 6. Flows

### 6.1 The operator defines a condition and sells on it

1. `/admin/entry-conditions` → **New condition**: key `student`, name "Student ticket", assertion text, document hint, verification `document_at_door`. Capability `registration.manage-entry-conditions`.
2. `/admin/registration-types/{id}` → **Entry conditions** — a multi-select of the brand's non-archived conditions, beside the grants editor. Saving emits `RegistrationTypeEntryConditionsChanged`.
3. Optionally `/admin/policies/{id}` → **Where this applies** → bind "Reduced-fare terms" to the entry condition `student`. Now that text is part of the student purchase and of no other.

### 6.2 The public registration form

`RegistrationController::show()` puts `entryConditions: list<PublicEntryConditionData>` on `RegistrationFormData` (id, key, name, assertionText, documentHint, verification) and renders the Compliance slot **`registration.consent`** with the context `{eventId, registrationTypeIds: [type], entryConditionIds: [...]}`. The page shows, in this order: the offer, the billing fields, the quantity control, **one required checkbox per entry condition** carrying `assertionText` verbatim with `documentHint` as help text, then the Compliance contribution (the bound and unbound texts this registration is under — a checkbox where the version says `requires_consent`, a notice where it does not, `terms` and `withdrawal` first and the rest by name, D-CPL-08), then the submit button.

`SubmitRegistrationData` gains `acceptedEntryConditionIds: list<string>` and `acceptedLegalTexts: bool`. Because a static ruleset cannot see which type the URL addressed, `SubmitRegistration::guardEntryConditions()` enforces per type — exactly as `guardBillingParty()` does — that every condition the type declares is in the posted list, raising a validation error keyed `entryConditions.{id}` on the checkbox that raised it (ADR-0055). A declaration nobody made is not a declaration, so the submission is refused — and where the type names an `alternative_registration_type_id` (§4.2), the error renders beside a **"Buy the regular ticket instead"** link to that type, because a dead end at the checkbox loses a sale the full-price type would have taken (D-REG-21).

`SubmitRegistration` then, inside its existing transaction: writes one assertion row per condition with the snapshotted text, emits `RegistrationEntryConditionAsserted` per condition and `RegistrationConsentGiven` once with the binding context. `RecordConsentDeclaration` (Compliance) resolves the same context and writes the consent rows.

### 6.3 Checkout

The text list depends on the provider, so the provider is chosen **first** and the texts are shown **after**: checkout is two steps (D-CPL-07).

1. **Step one — choose the provider.** `CheckoutController::show()` / `CartController::show()` render the settlement accounts on offer, the order summary and the existing `EXEMPTION_NOTICE`, and nothing else legal. The payer picks an account and posts it; the choice is held on the pending order (or the cart's session state), and it is not yet a declaration of anything.
2. **Step two — the confirmation.** The confirmation pane builds the binding context from what Commerce holds — `brandId`, the opaque `binding_context` bag carried on the order lines (§7.2), `orderId` — **plus the chosen provider key**, and calls `CheckoutConsentSlots::contributions($context)`. It lists exactly the texts that apply to this purchase through this provider (checkbox or notice per `requires_consent`), shows what is being bought and how it will be paid, carries the one accept control, and offers a *Back* link to step one which clears the acceptance.
3. `start()` validates `account` + `accept_terms` against the provider recorded in step one, emits `CheckoutConsentGiven` with `bindingContext` **including that provider**, then resolves the adapter and redirects. The declaration is still recorded before the payer leaves, as today.
4. `RecordCheckoutConsent` resolves `PolicyResolver::textsFor($event->bindingContext)` — the same context the confirmation step rendered — and writes one row per version with `policy_binding_id` and the snapshot. It no longer re-derives the list from the brand alone, which is what keeps the rows and the page from drifting.

**A brand with a single offered account skips step one**: there is no choice to make, so the payer lands straight on the confirmation step with that provider already resolved. Changing the provider always means going back, which always re-resolves the list and always clears the acceptance — a payer may not be held to have agreed to a list they never saw.

### 6.4 The door

`ConfirmRegistration` adds `entryConditionKeys: list<{key,label,verification,documentHint}>` to the `RegistrationConfirmed` payload (alongside `grants`, not inside it — a condition is a property of the act, not of one grant). Admission stamps it on every admission it issues for that registration and serves it in the entrance payload. The offline client shows, under an accepted scan: **"Document check — Student ID"**. Door staff tap *confirmed* or *refused*; the scan posts `conditionsChecked: [{key, outcome}]`, which rides on `AdmissionCheckedIn`. Registration's `RecordDoorVerification` listener stamps the matching assertion `verified`/`refused` with `decided_via: door`. A refusal does **not** revoke the admission — ADR-0005's prohibition stands, the door decides access, not money — it records the fact and raises a metric the operator can act on.

### 6.5 Re-consent to a contract

- **At login**: unchanged, shipped (`FlagOutstandingContractConsent` → `compliance.consent_redirect` → `policies.required`). Brand-wide question, no binding context — a bound text cannot be asked for at login, because at login there is no event, no type and no provider. That is correct: a bound text is asked at the act it binds.
- **In a running session, before a critical act**: a **second alias**, `require-contract-consent` (`EnsureContractConsent`), registered by Compliance's provider beside `require-consent` and applied by the owning module to the routes it knows are contract-creating (D-CPL-13, D-CPL-14; the list is in §14.20). It asks the narrow `subjectHasOutstandingContract()`, passes guests through, stores `url.intended` on GET only, redirects to `policies.required`. It is never applied to `policies.*`, the login routes, `/my/*` read surfaces, or the Support report channel (ADR-0063) — a gate in front of the channel by which someone reports that the gate is broken is the worst possible loop.
- **The interstitial**: `policies.required` as it stands, for both the login and the mid-session case (D-CPL-15), with two additions — when the gate was raised by a *bound* text, the page names what it belongs to ("Before you continue with your registration for Summer LAN 2026"), taken from the session and not from a Registration query; and a **"Not now"** control that leaves the gated act, says plainly what cannot be done until the text is agreed to, and keeps the account, the tickets and the order history reachable. "Not now" has **no counter of its own**: the refusal rate is derived from prompts against grants (§10, D-CPL-17). A modal was rejected outright — a legal text behind a dismissible overlay is not an agreement.

## 7. Module boundaries and seams

### 7.1 Registration → Compliance

Registration never imports Compliance. Two touch points, both existing shapes:

- The slot `registration.consent`, declared by **Registration** (the page owns its extension points) and filled by **Compliance**, mirroring `checkout.consent` exactly. Context: `{eventId, registrationTypeIds, entryConditionIds}`.
- The event `RegistrationConsentGiven` (Registration's own, mirroring Commerce's `CheckoutConsentGiven`), carrying `brandId`, `subjectId`, `captureContext: 'registration'`, `acceptedAt`, `bindingContext` (scalars) — the per-module pattern, extended, not a shared Platform event (D-CPL-10).

### 7.2 Commerce → Compliance

Commerce must hand over a context containing registration types and entry conditions, and Commerce may not read Registration's tables. The answer (D-CPL-06): **`RegistrationSettlementRequested` grows a `binding_context` payload field** (scalars: `event_id`, `registration_type_id`, `entry_condition_ids`), Commerce persists it on the order line as an **opaque JSON bag** it never interprets, and hands the union of its lines' bags to the slot. Commerce carries foreign ULIDs — which ADR-0004 explicitly permits — and interprets none of them; Compliance is still the only module that knows what they mean. The facts are frozen at the moment the order was created, which is what the consent record should quote anyway.

**No backfill.** Order lines that exist at deploy time carry a null bag; they resolve unbound policies only, exactly as they do today. A migration that invented a context for a purchase nobody was asked about would manufacture evidence.

Commerce's own additions to the context are the two facts it owns: the chosen `settlementProvider` key and the `orderId`.

### 7.3 Labelling binding targets in the console

The binding editor must show "Event: Summer LAN 2026" and "Type: Student weekend", and Compliance may read neither Event's nor Registration's tables. The seam (D-CPL-11), modelled on `PublicLegalTexts` (#184) and `SlotRegistry`:

`Konventix\Platform\Targets\TargetDirectory` — a Platform-owned boot-time registry. Each module registers a provider for the kinds it owns, from its own ServiceProvider (ADR-0003): Event registers `event`, Registration registers `registration_type` and `entry_condition`, Commerce registers `settlement_provider`. A provider answers `optionsFor(?string $brandId): list<{kind, id, label, group}>` and `labelFor(string $kind, string $id): ?string`. Compliance reads the registry and never a foreign model; the kinds are generic ("name these ids for a picker"), so nothing in Platform names a policy. An unknown id is a renderable state, never a crash, and the picker is only as complete as the modules that registered — which its empty state says.

### 7.4 Identity → Compliance

Unchanged: the one session key `compliance.consent_redirect`, spelled out by hand on both sides.

## 8. Events and read models (ADR-0004)

New, all payloads scalars:

| Event | Owner | Payload |
|---|---|---|
| `EntryConditionCreated` / `EntryConditionUpdated` / `EntryConditionArchived` | Registration | `entry_condition_id`, `brand_id`, `key`, `name`, `verification` |
| `RegistrationTypeEntryConditionsChanged` | Registration | `registration_type_id`, `brand_id`, `entry_condition_ids` |
| `RegistrationEntryConditionAsserted` | Registration | `assertion_id`, `registration_id`, `entry_condition_id`, `entry_condition_key`, `brand_id`, `subject_user_id`, `verification`, `asserted_at` |
| `RegistrationEntryConditionDecided` | Registration | `assertion_id`, `registration_id`, `entry_condition_key`, `state`, `decided_by_user_id`, `decided_via`, `reason_code`, `decided_at` |
| `RegistrationConsentGiven` | Registration | `brand_id`, `subject_id`, `capture_context`, `accepted_at`, `binding_context` |
| `PolicyBound` / `PolicyBindingRemoved` | Compliance | `policy_id`, `binding_id`, `brand_id`, `kind`, `target_key`, `changed_by_user_id` |
| `ConsentPromptRaised` | Compliance | `brand_id`, `subject_id`, `surface` (`login\|checkout\|registration\|route_gate`), `reason` (`contract\|required`) — the metric's source, so the recorder subscribes to an event like every other (ADR-0059) |

Changed:

- `RegistrationConfirmed` gains `entry_condition_keys` (list of `{key, label, verification, document_hint}`).
- `RegistrationSettlementRequested` gains `binding_context`.
- `AdmissionCheckedIn` gains `conditions_checked` (list of `{key, outcome}`, empty for every scan that checks nothing).
- `CheckoutConsentGiven` gains `binding_context`.

`EntryConditionCreated`/`Updated`/`Archived`, `PolicyBound` and `PolicyBindingRemoved` implement `AuditableEvent`, so Compliance's wildcard subscriber records them — binding a legal text to a product is a privileged act and belongs in the audit trail.

**No new read models.** Admission stamps scalars on its own rows rather than projecting a condition table; Compliance stores a target ULID and asks the registry for a label; Commerce carries an opaque bag. The one thing that would have needed a read model — the console's labels — is the registry instead.

## 9. Console and public surfaces

### 9.1 Registration console

- `/admin/entry-conditions` — index and editor (`registration.manage-entry-conditions`, D-REG-24). Fields per §4.1; the assertion text gets a character budget and a live preview of the checkbox as the registrant will see it; an `operator_checked` condition says on the editor that it fills a worklist and does *not* gate confirmation — that is `approval: required` on the type.
- Type editor — an **Entry conditions** card beside Grants, multi-select with the condition's assertion text shown inline, plus the optional **alternative type** pointer (§4.2). The card stays editable after the type has sold (D-REG-19) and warns before saving on a type with registrations, naming how many registrations a new condition will leave *not asserted* and how many historic assertions a removal leaves behind.
- Registration detail — the assertions with their state, the *not asserted* rows for conditions added after the registration and the *no longer required* rows for conditions since removed (§4.2), and *Verify* / *Refuse* / *Waive* buttons behind `registration.verify-entry-conditions`, writing `RegistrationEntryConditionDecided`.
- Registration index — a filter "entry condition state" so an operator can work the `asserted` pile before the event; for `operator_checked` conditions this filter **is** the worklist (D-REG-22).

### 9.2 Compliance console

- Policy editor gains a **Where this applies** card behind Compliance's own `compliance.manage-policy-bindings` capability (D-CPL-16 — binding a text changes who is legally bound by it without changing a word of it, so it is not `ManagePolicies`): the bindings as chips grouped by kind, an "Add binding" picker fed by `TargetDirectory`, and the two sentences that decide the whole feature — *"No bindings: this text applies everywhere this brand sells"* (D-CPL-04) and, as soon as a second kind is bound, the rule in words: *"Applies when any of the events match **and** any of the types match **and** any of the conditions match **and** any of the providers match"* (D-CPL-03). The card spells out that a kind with no binding places no requirement.
- A binding whose target the directory cannot label renders as `Unknown (event · 01HX…)` with a *Remove* button — the dangling-binding warning, visible where it can be fixed.
- Policy index gains a "bound" marker so it is obvious at a glance which texts are conditional.
- The #222 gap notice is unchanged: it asks the brand-wide question about the two roles, which bindings do not touch.

### 9.3 Public

- Registration form: §6.2. WCAG 2.1 AA — each checkbox has a real `<label>` carrying the full assertion text (never a truncated one with the text behind a link), `aria-describedby` for the document hint and the error, visible focus ring, semantic tokens only (ADR-0008).
- Pay page / cart checkout: two steps per §6.3 — the provider first, the texts and the accept control on the confirmation step — plus the existing `EXEMPTION_NOTICE`, unchanged and still not a policy.
- Ticket: the condition's `name` and `document_hint` print on the ticket PDF beside the holder ("Student ticket — bring a valid student ID"), so nobody arrives at the door without what the door will ask for. **The badge carries nothing** (D-ADM-07).
- `policies.required`: §6.5.

## 10. Observability (ADR-0059)

Compliance's first metrics catalogue, `app-modules/compliance/src/Metrics/ComplianceMetrics.php`, recorders in `src/Listeners/Metrics/`:

- `consent_prompts_total{brand, surface, reason}` — counter on `ConsentPromptRaised`. `surface ∈ login|checkout|registration|route_gate`, `reason ∈ contract|required`. Bounded sets, no ids, no policy slugs (an operator-defined slug is unbounded cardinality).
- `consent_grants_total{brand, surface}` — counter on `ConsentGranted`, `surface` from `capture_context` mapped onto the same bounded set.
- `consent_withdrawals_total{brand}` — counter on `ConsentWithdrawn`.
- `policy_binding_matches_total{brand, kind}` — counter, one per binding that matched a resolution; the operator's answer to "is this text actually being asked for?"
- `outstanding_contract_subjects{brand}` — gauge at scrape time, `withoutBrandScope()` and grouped by brand.

Registration:

- `entry_condition_assertions_total{brand, verification}` — counter on `RegistrationEntryConditionAsserted`. **Verification, not the condition key**: keys are operator-defined and unbounded.
- `entry_condition_decisions_total{brand, state, source}` — counter on `RegistrationEntryConditionDecided`.

Panel: a **Consent and conditions** row in `contrib/observability/src/platform.mjs` — prompts by surface, grants against prompts (the refusal rate is **derived** from those two series, D-CPL-17; the interstitial's "Not now" button has no counter of its own), bound-policy matches by kind, outstanding contract subjects, assertions and door refusals. `npm run observability:build`.

Alerts in `contrib/observability/alerts/konventix.rules.yaml`, each with a vmalert-tool unit test and a `docs/operations.md` anchor:

- `KonventixConsentGateBlocking` — `increase(consent_prompts_total[15m]) > 5 and increase(consent_grants_total[15m]) == 0`, warning. Everyone is being asked and nobody is agreeing: a loop, an unpublished text, or a broken interstitial. The failure mode this feature can produce that nothing else would catch — the sale stops and the logs look healthy.
- `KonventixEntryConditionRefusalsAtDoor` — `increase(entry_condition_decisions_total{state="refused",source="door"}[10m]) > 3`, warning, during an event: either a reduced-fare type is being abused or the door is misreading the hint.

Log: `ConsentPromptRaised` writes one `compliance.consent-prompt` info record with the subject id, surface and reason — ids only, never the policy text or an address (`.ai/rules/observability.md`).

## 11. Privacy, retention and GDPR

- **An assertion is personal data.** "This person declares they are a student" is a statement about someone's education or employment status. It is not an Art. 9 special category, but data minimisation applies with full force: the platform stores **the declaration, not the proof**. No uploads, no scan of a student card, no document number, no expiry date. The door looks at a physical ID and records `confirmed` or `refused` — and a refusal stores a bounded `reason_code`, never a sentence about the person. Uploads are excluded by decision, not by omission (D-ADM-07); `document_upload` would need its own ADR (§15).
- **The door sees a label.** For a `document_at_door` condition, door staff necessarily learn "this holder bought a student ticket". That is the minimum the feature needs to work; it is shown in the scan result and nowhere else, and it is not in the metric labels or the log records. The PII-free delta feed stays PII-free: the condition rides on the admission payload the door already downloads for its own holders, not in the revocation feed.
- **Consent rows grow a context snapshot** (§5.5). It contains ULIDs of a registration and an order the subject already owns — no new category of data, and it is what makes the record evidence rather than a claim.
- **GDPR export** (`Registration\Gdpr\`): assertions as `{condition name, asserted text, asserted_at, state, decided_at}` — the wording the person actually agreed to, because that is what they are entitled to see. Compliance's handler already exports consents; it adds `binding_context` and the bound policy's name.
- **Deletion**: anonymize with the registration (Round 6 D-BATCH — anonymize by default, tombstone survives): `subject_user_id` and `decided_by_user_id` nulled, the row kept so the registration's history stays coherent. Idempotent.
- **Retention**: the assertion's clock is the registration's own, started by `EventConcluded` — Compliance publishes the rule, Registration deletes its own rows (`RetentionPeriodElapsed`), exactly as every other category. It is **not** invoice data and must not inherit the ten-year bookkeeping floor (D-REG-23): the invoice carries the price it charged and does not need the reason beside a name for a decade.
- **Purpose limitation**: an entry-condition assertion exists to price a ticket and to check it at the door. It is not a marketing segment, and it must not appear in the attendee list (ADR-0064), on a public profile, or in any export that is not the subject's own.

## 12. Testing

Pest, per module.

**Registration** (`app-modules/registration/tests/`)
- `EntryConditionCrudTest` — create/update/archive, key uniqueness per brand, capability denial, archived conditions hidden from the type editor and kept on past registrations.
- `EntryConditionOnRegistrationTypeTest` — attach/detach, ordering, the alternative-type pointer, the **live-type rule** (§4.2: a type that has sold still accepts a new condition; no assertion row is written for the registrations that exist; the detail shows them *not asserted* and a waive is possible; removing a condition keeps the historic assertions and archives rather than deletes; admissions already issued keep their stamped list), and that a type with no conditions renders and submits exactly as today.
- `EntryConditionAssertionTest` — the form shows one checkbox per condition with the verbatim text; an unticked condition is a 422 on `entryConditions.{id}` and the error carries the alternative-type link where one is set; a submission writes the snapshot; the snapshot does not move when the condition's text is reworded; one assertion for a `quantity: 3` submission.
- `EntryConditionVerificationTest` — console verify/refuse/waive, `RegistrationEntryConditionDecided`, door verification through `AdmissionCheckedIn`, a door refusal does not revoke the admission.
- `RegistrationConsentDeclarationTest` — `RegistrationConsentGiven` carries the binding context; `Event::fake([...])` with explicit classes.
- `RegistrationGdprHandlerTest` — assertions exported and anonymized.

**Compliance** (`app-modules/compliance/tests/`)
- `PolicyBindingTest` — bind/unbind per kind, uniqueness, operator-wide bindings inherited, `PolicyBound` audited, a dangling binding never matches.
- `PolicyResolverTest` — **the centre of the suite.** An unbound policy resolves everywhere (the no-regression case for today's behaviour); a type-bound policy resolves only for that type; a provider-bound policy only for that provider; **the combination rule** (two event bindings are alternatives; an event binding *and* a provider binding both have to match; a kind with no binding places no requirement; a bound kind whose context value is missing never matches); an entry-condition-bound policy resolves from the condition ids in the context; a bound policy with no published version resolves to nothing rather than to a broken link; the resolution is brand-correct when the operator's text is shadowed; the returned order is `terms`, `withdrawal`, then the rest by name, and `requires_consent` alone decides checkbox versus notice.
- `CheckoutContractConsentGateTest` (existing, extended) — the two-step path: step one records the provider and shows no texts, step two lists the texts resolved *with* that provider, going back clears the acceptance, a brand with one settlement account lands on step two directly, and `RecordCheckoutConsent` writes rows for exactly the versions the confirmation step rendered.
- `ContractReconsentGateTest` — `require-contract-consent` blocks a contract-outstanding subject on a critical route, passes a consent-basis-outstanding subject through, passes guests through, preserves `url.intended` on GET only, and is not applied to the policy pages, the login routes, `/my/*` or the Support channel; a route-name assertion lists the contract-creating routes that must carry the alias (D-CPL-13); the "Not now" path leaves the act and increments no counter of its own.
- `CheckoutLegalTextConventionTest` (existing) — still green **unchanged**: there is no third role, and `terms`/`withdrawal` keep their descriptions (D-CPL-08).
- `ComplianceMetricsTest` + `ObservabilityCoverageTest`; alert unit tests via vmalert-tool.

**Commerce**
- `CheckoutBindingContextTest` — the opaque bag survives `RegistrationSettlementRequested` → order line → slot context; Commerce interprets none of it.
- Architecture test (`tests/Unit/ArchitectureTest.php`) unchanged and still green: **no new cross-module import in either direction** is the single most important assertion in this design.

**Platform**
- `TargetDirectoryTest` — each module's provider registers its kinds, labels resolve, an unknown id returns null rather than throwing.

**Vue (vitest)**
- The entry-condition checkboxes render the full text and block submission unticked; the checkout confirmation pane clears the acceptance when the provider behind the list changed; `policies.required` names the act when the session says so and its "Not now" control leaves the act.

## 13. Decisions taken

Everything in this section is settled. §13.1 holds the rules that were settled before the interview; §13.2 holds the twenty-five answers the operator gave on 2026-09-21, recorded in the decision log as Round 10. §14 states each answer in full, with its consequence and — where the answer went against this document's recommendation — the reason the recommendation lost.

### 13.1 Standing rules

| Id | Decision |
|---|---|
| D-REG-16 | Entry conditions, policy bindings and contract re-consent are **designed now and built directly on `develop`** — one spec (this document) plus one ADR, no GitLab issues. (Operator, 2026-09-21, decision log Round 9.) |
| T-1 | **Entry conditions belong to Registration**, policy bindings belong to **Compliance**, and neither module imports the other. Compliance stores an entry-condition **ULID** as an opaque target. (ADR-0004.) |
| T-2 | **Commerce never learns that a policy exists.** The `checkout.consent` slot stays the seam; what changes is that it is now handed a context bag of scalars. (ADR-0004, #2, #200.) |
| T-3 | **An unbound policy keeps today's behaviour**, unconditionally. The migration adds a table and changes no existing row's meaning; the three seeded placeholders and the two checkout roles are untouched. |
| T-4 | **A binding hangs on the policy, not on the version.** Rebinding a text is not a new version of it; the version is what a person consented to. |
| T-5 | **Consent is still recorded against a version id**, and a consent for a version silences every gate that asks for it (#205's property, which is what stops the gates asking twice). |
| T-6 | **The assertion text is snapshotted** on the assertion row, as consent is bound to a version: evidence may not change under the person who gave it. This is what makes a freely editable condition set (D-REG-19) safe. |
| T-7 | **No documents are stored.** Verification is a declaration plus, where the condition says so, a physical check at the door that records an outcome code. |
| T-8 | **The door never decides money.** A refused entry condition records a fact and raises a metric; it does not revoke an admission and does not reach Commerce. (ADR-0005.) |
| T-9 | **The re-consent gate is never applied** to `policies.*`, the login and logout routes, `/my/*` read surfaces, or Support's report channel (ADR-0063). |
| T-10 | **Over-binding over-asks.** Every ambiguous *resolution* resolves towards showing one text too many rather than one too few — with one deliberate exception: combining several bindings ANDs across kinds (D-CPL-03), which narrows. The operator took that narrowing knowingly, in exchange for being able to express "this addendum, at this event, through this provider" at all. |
| T-11 | **Metric labels are bounded sets** — surface, reason, kind, verification, state. Never a policy slug or a condition key, both of which are operator-defined. (ADR-0059.) |

### 13.2 The operator's answers (decision log Round 10, 2026-09-21)

| Id | Question | Decision |
|---|---|---|
| D-REG-17 | Scope of an entry condition (§14.1) | Brand-wide and reusable, with an optional `event_id` pinning a one-off condition to one event. |
| D-REG-18 | How a type declares conditions (§14.2) | A pivot table: several reusable conditions per type. |
| D-REG-19 | Editing conditions on a live type (§14.3) | **Freely editable after the type has sold** — *against the recommendation*. Existing registrations keep their snapshots; a condition added later is *not asserted* for them and the console shows that state; removing a condition keeps the historic assertions (§4.2). |
| D-REG-20 | Assertions and `quantity` (§14.4) | One assertion per registration; per-holder declarations through claim links are a follow-up. |
| D-REG-21 | Someone will not tick the box (§14.5) | Refused on submit, **plus** the optional "buy the regular ticket instead" link to an alternative type. |
| D-REG-22 | Verification kinds (§14.6) | The three kinds; `operator_checked` is advisory and fills a worklist; a blocking check is `approval: required` on the type. |
| D-REG-23 | Retention of an assertion (§14.24) | The registration's own clock, published as a Compliance rule. |
| D-REG-24 | Registration capabilities (§14.23) | `ManageEntryConditions` and `VerifyEntryConditions`. |
| D-ADM-06 | Depth of the door integration (§14.7) | The label rides on the admission, the scan shows it, the door records `confirmed`/`refused` onto the assertion, queued offline like every other scan write. |
| D-ADM-07 | Documents and visibility of the label (§14.8) | Never a document. Visible to the door and the console; the **document hint** prints on the ticket PDF; **nothing on the badge**. |
| D-CPL-02 | Binding target model (§14.9) | One `kind` plus one `target_key` string. |
| D-CPL-03 | Combining several bindings (§14.10) | **OR within a kind, AND across kinds** — *against the recommendation*. The resolver's rule and the console's wording say so, and "Stripe addendum only at event X" is expressible directly. |
| D-CPL-04 | A policy with no bindings (§14.11) | Applies everywhere this brand sells — today's behaviour; the console says so out loud. |
| D-CPL-05 | Binding an operator-wide policy (§14.12) | Allowed; the binding carries a `brand_id`, null when the operator binds globally. |
| D-CPL-06 | How the checkout learns types and conditions (§14.13) | `binding_context` on `RegistrationSettlementRequested`, persisted on the order line, **no backfill**. |
| D-CPL-07 | Provider versus the consent control (§14.14) | **Two-step checkout** — *against the recommendation*: choose the provider, then a confirmation step listing the texts with the accept control; a single-provider brand goes straight to the confirmation step. |
| D-CPL-08 | The role of a bound text at checkout (§14.15) | **`requires_consent` alone decides** — *against the recommendation*: every matching bound policy is shown, the version's `requires_consent` decides checkbox versus information, no new role column, ordering `terms`/`withdrawal` first and then the rest by name. |
| D-CPL-09 | What a consent record carries (§14.16) | `policy_binding_id` plus a `binding_context` snapshot. |
| D-CPL-10 | Which event carries a declaration (§14.17) | The per-module events, extended. |
| D-CPL-11 | Labelling binding targets (§14.18) | A Platform-owned `TargetDirectory` registry. |
| D-CPL-12 | What marks a text as a contract (§14.19) | `lawful_basis = contract`, reused; no second flag. |
| D-CPL-13 | Which acts are critical (§14.20) | The contract-creating acts only. |
| D-CPL-14 | Where the gate lives (§14.21) | The `require-contract-consent` alias, registered by Compliance and applied by the owning module. |
| D-CPL-15 | The interstitial (§14.22) | `policies.required`, reused, with one line naming the act. |
| D-CPL-16 | The binding capability (§14.23) | Compliance's own `ManagePolicyBindings`, separate from `ManagePolicies`. |
| D-CPL-17 | Measuring a refusal (§14.25) | Derived from prompts against grants, plus a "Not now" button with no counter of its own. |

## 14. The operator's answers (interview, 2026-09-21)

Twenty-five questions, twenty-five answers. Each one is a fact; the options that were not chosen are gone. A ***Rejected*** line is kept only where the answer went against this document's recommendation, so the reason the recommendation lost survives the choice.

### Entry conditions

**1. What scope does an entry condition live at? — D-REG-17**
- **Brand-wide and reusable across events, with an optional `event_id`** for a one-off condition. "Student" is written once and reused for years, and a shared ULID is what a policy binding points at.
- *Consequence:* the console gets its own `/admin/entry-conditions` screen, and archiving rather than deleting is the rule (§4.1, §9.1).

**2. How does a registration type declare its conditions? — D-REG-18**
- **A pivot table**: several conditions per type, each reusable, with a `position` for the order they render in. A "Student crew" type needs two conditions, and a JSON list cannot be joined from a binding resolution.
- *Consequence:* one extra table and one extra eager load on the public form (§4.2).

**3. Do entry conditions freeze once a type has registrations (ADR-0060)? — D-REG-19**
- **No: they stay freely editable after the type has sold.** `entryConditions` does not join `frozenAxes()`. The evidence lives in the assertion snapshot, not in the pivot, so an edit cannot rewrite what anyone declared.
- **How existing registrations are treated** (the rule this answer obliges the design to state, in full in §4.2): existing registrations **keep their assertion snapshots** — they asserted what they were shown; a **newly added condition is "not asserted"** for them, written nowhere and shown as such on the registration detail, waivable by an operator and blocking nothing; **removing a condition keeps the historic assertions**, shown as *no longer required by this type*, with the condition archived rather than deleted; admissions already issued keep the condition list they were stamped with; and the type editor warns, before saving, how many registrations a change affects.
- *Consequence:* an operator who forgot a condition fixes the type instead of creating a second one, and the console carries the honesty — a *not asserted* row is visible everywhere the assertions are.
- ***Rejected:*** freezing with the other axes (the recommendation), because it forces a duplicate type for a forgotten checkbox. The recommendation's objection — that adding a condition retroactively claims people asserted something they were never shown — is answered by never writing an assertion for them and showing *not asserted* instead of silence.

**4. What is an assertion attached to when `quantity > 1`? — D-REG-20**
- **One assertion per registration**: the registrant asserts once, for the contractual act.
- *Consequence:* a parent buying three student tickets asserts once; the door still checks three IDs, because the label is on every admission. Per-holder declarations collected on a claim link are the honest follow-up (§15).

**5. What happens when someone will not tick the box? — D-REG-21**
- **The submission is refused** with a validation error on the checkbox that raised it, **and** the form offers the equivalent non-conditional type where the type names one ("Buy the regular ticket instead"). A declaration nobody made is not a declaration; a dead end at the checkbox loses a sale the full-price type would have taken.
- *Consequence:* `registration_types` gains a nullable `alternative_registration_type_id`, used for that link and nothing else (§4.2, §6.2).

**6. What is the catalogue of verification kinds, and does `operator_checked` block confirmation? — D-REG-22**
- **The three kinds** — `self_declared`, `document_at_door`, `operator_checked` — and `operator_checked` is **advisory**: it fills an operator worklist and never gates confirmation. ADR-0005 already has an axis for "a human decides before this is confirmed"; a second one that silently gated confirmation would be two mechanisms for one thing.
- *Consequence:* an operator who wants a blocking check also sets `approval: required` on the type, and the condition editor says so. The registration index filter on assertion state **is** the worklist.

**7. How deep does the door integration go? — D-ADM-06**
- **The label rides on the admission, the scan result shows it ("Document check: Student ID"), and the door records `confirmed`/`refused` back onto the assertion.** A check nobody records is a check the operator cannot prove happened.
- *Consequence:* `AdmissionCheckedIn` gains `conditions_checked`, and the offline client gains the control — **queued like every other offline write** and replayed on reconnect, idempotent per scan. A refusal still never revokes an admission (T-8).

**8. May a condition ever carry a document, and how visible is the label? — D-ADM-07**
- **Never a document.** The label is **visible to the door and to the operator console**; the **document hint prints on the ticket PDF**; **nothing goes on the badge**. The holder needs to know what to bring; the room does not need to know what they paid.
- *Consequence:* badge templates and `BadgeDocumentRenderer` are untouched, the ticket renderer gains one line, and `document_upload` stays out of the platform (§15).

### Policy bindings

**9. How is a binding target modelled? — D-CPL-02**
- **One `kind` plus one `target_key` string(64)** — ULIDs for the three entity kinds, the provider key for a provider. A settlement provider is a string key and not an entity, so a typed-column scheme would already be inconsistent, and a fifth kind must not cost a migration.
- *Consequence:* the resolver joins on `(kind, target_key)` and the console does the type-checking the database is not doing.

**10. How do several bindings on one policy combine? — D-CPL-03**
- **OR within a kind, AND across kinds.** Two event bindings are alternatives; an event binding and a provider binding must both match. The rule in words, in the resolver and on the console card alike: *"applies when any of the events match **and** any of the types match **and** any of the conditions match **and** any of the providers match."* A kind with no binding places no requirement; a bound kind whose context value is absent cannot match.
- *Consequence:* **"our Stripe addendum, but only at the big event" is now expressible directly**, with two bindings on one policy and no second policy and no `binding_group` column. The console must state the rule, because an AND nobody notices under-asks.
- ***Rejected:*** a flat OR across every binding (the recommendation), whose safety came from over-asking. The operator wants the narrow case expressible and accepts that T-10's "resolve towards asking" yields here; the mitigation is that the rule is written on the card where bindings are made, not only in this document.

**11. What does a policy with no bindings mean? — D-CPL-04**
- **It applies everywhere this brand sells** — today's behaviour, unchanged, and the console says so on the binding card.
- *Consequence:* no cutover risk: every existing brand's AGB keeps being asked for on the day after deploy.

**12. May an operator-wide policy (`brand_id IS NULL`, #76) be bound, and by whom? — D-CPL-05**
- **Yes.** The binding carries a `brand_id` where a brand binds the operator's text to its own event, and null where the operator binds globally. `MayBeOperatorWide` already handles the nullable-brand shape.
- *Consequence:* the resolver's query carries the same `withoutBrandScope()`-plus-filter dance every other Compliance query does.

**13. How does the checkout learn which types and conditions an order covers? — D-CPL-06**
- **`RegistrationSettlementRequested` carries an opaque `binding_context` bag**; Commerce persists it on the order line and hands the union of its lines' bags back to the slot, interpreting none of it. **No backfill**: existing order lines carry null and resolve unbound policies only, exactly as today.
- *Consequence:* one payload field, one JSON column on `order_lines`, and no module grows a mirror of foreign rows (§7.2).

**14. When is the settlement provider known, relative to the consent control? — D-CPL-07**
- **Checkout becomes two steps**: the payer chooses the provider, then a **confirmation step** lists the texts that apply to that purchase through that provider and carries the accept control. **A brand with a single settlement account skips step one** and lands on the confirmation step directly. Going back always re-resolves and always clears the acceptance.
- *Consequence:* the pay page and the cart checkout gain a confirmation pane and one more POST; the provider is a recorded choice before any legal text is shown, which is what makes the list exact (§6.3).
- ***Rejected:*** the one-page partial reload (the recommendation). It kept the one-page checkout, but it re-renders the most sensitive control on the most sensitive page and asks the payer to notice that the list moved under them. A separate step makes "these are the texts for what you just chose" the whole content of the page.

**15. What role does a bound text play at checkout? — D-CPL-08**
- **`requires_consent` alone decides.** Any matching bound policy is shown; the version's `requires_consent` decides checkbox (agree) versus information (be informed). **No new role column.** Ordering: the existing `terms` and `withdrawal` first, in that order, then the rest by name.
- *Consequence:* `CheckoutLegalTextRoleData` and `CheckoutLegalTextConventionTest` are unchanged, and an operator gives a text a role by publishing a version that does or does not require consent — one lever, not two.
- ***Rejected:*** a `checkout_role` column (the recommendation). A third role would be a second, operator-invisible way of saying what `requires_consent` already says, and the two could disagree.

**16. What does a consent record carry about the binding? — D-CPL-09**
- **`policy_binding_id` plus a `binding_context` JSON snapshot.** "Why was this person asked, and for which purchase" is the question a dispute asks, and a binding id alone answers only the first half.
- *Consequence:* one JSON column on `consents`, exported to the subject, never queried across rows (§5.5).

**17. Which event carries the declaration from a page to Compliance? — D-CPL-10**
- **The per-module pattern, extended**: `CheckoutConsentGiven` (Commerce) and a new `RegistrationConsentGiven` (Registration), both with a binding context.
- *Consequence:* a third surface later means a third event — acceptable, and each one names its own capture context honestly.

**18. How does the binding console label its targets? — D-CPL-11**
- **A Platform `TargetDirectory`** registry that modules contribute options and labels to, in `SlotRegistry`'s shape.
- *Consequence:* a new Platform contract and four small providers; "unknown target" is a renderable state with a *Remove* button rather than a crash, and the picker's empty state says it is only as complete as the modules that registered (§7.3).

### Re-consent to a contract

**19. What marks a policy as a contract for re-consent? — D-CPL-12**
- **`lawful_basis = contract` on the version**, reused — what `subjectHasOutstandingContract()` already asks.
- *Consequence:* an operator who wants a non-contract text re-asked raises it through `require-consent` on a route, which is exactly what that alias is for.

**20. Which acts are "critical" and carry the gate? — D-CPL-13**
- **The acts that create or change a contract**: registration submit, checkout start (already), cart checkout, amendment, waitlist conversion, ticket-claim acceptance. Nothing else.
- *Consequence:* someone with an outstanding AGB version keeps their account, their tickets and their order history, and is stopped only when they try to buy or claim.

**21. Where does the gate live? — D-CPL-14**
- **A second Compliance alias, `require-contract-consent`**, registered by Compliance's provider and applied by the owning module to its own critical routes (ADR-0003), mirroring `require-consent`.
- *Consequence:* adding a critical route later means remembering the alias — mitigated by a test that asserts the expected route names carry it (§12).

**22. What does the interstitial look like? — D-CPL-15**
- **`policies.required`, reused for both the login and the mid-session case**, with one line naming the act when the session knows it.
- *Consequence:* the page gains one optional prop and the session one optional key. A modal is out: a legal text behind a dismissible overlay is not an agreement.

### Cross-cutting

**23. What capability governs bindings and conditions? — D-CPL-16 / D-REG-24**
- **Compliance gets its own `ManagePolicyBindings`**, separate from writing the text: binding a text to a product changes who is legally bound by it without changing a word of it. **Registration gets `ManageEntryConditions` and `VerifyEntryConditions`** — defining a fare condition and judging someone's document at the door are different jobs.
- *Consequence:* one more entry in Compliance's `Permission` enum, two in Registration's, and the default roles; `permissions:sync` materialises them for every brand.

**24. What retention does an assertion have? — D-REG-23**
- **The registration's own clock**, started by `EventConcluded` and published as a Compliance rule; Registration deletes its own rows.
- *Consequence:* the operator can say, if asked, that a reduced fare's justification is kept only as long as the registration itself — and never for the ten-year bookkeeping period, which belongs to the invoice.

**25. How is a *refusal* measured? — D-CPL-17**
- **Derived**: prompts and grants are counted separately and the refusal rate is the difference, on the dashboard. The interstitial gains a **"Not now"** button **without its own counter** — it exists for honesty, because a consent screen with only one way out is not a choice.
- *Consequence:* the interstitial's "Not now" path signs the person out of the gated act and says plainly what they cannot do until they agree; no new metric, no new label (T-11).

## 15. Open questions (not for this build)

- **Per-holder declarations.** D-REG-20 settles one assertion per registration. The honest follow-up is a declaration collected from each holder on their claim link (ADR-0032), which needs its own subject key, its own snapshot and its own door semantics. Named here so it is a known next step and not a gap.
- **Guest registration.** D-REG-08 defers registrants without accounts. When it lands, an assertion needs the Compliance-issued subject key, and the consent rows for a bound policy need it too. Nothing here blocks it; nothing here solves it.
- **Several brands, one person, one condition.** ADR-0016 gives one account per operator, and an assertion is per registration — so someone who is a student on two brands asserts twice. Correct today; a "verified attribute on the account" would be a different feature with a much larger privacy footprint.
- **Price enforcement.** Nothing checks that a student type is actually cheaper, or that someone does not buy both a student and a full ticket. Out of scope; quotas are the existing lever.
- **A binding that changes after someone consented.** Rebinding a text does not invalidate consents already given under it — `T-4`/`T-5` — but the console should probably say how many consents a binding has produced before it is removed. Deferred until there are bindings to count.
- **Binding groups.** D-CPL-03's AND-across-kinds covers "this provider at this event". What it cannot express is two independent groups on one policy ("Stripe at event X, *or* Mollie at event Y"); that needs the `binding_group` column, and the answer today is two policies.
- **Translations of a policy's content.** Unchanged by this design and still unsolved platform-wide.
- **`document_upload` verification.** Deliberately excluded (§14.8). If it ever lands it needs its own ADR: media retention, an access capability, and a much sharper deletion story.

## 16. Build order

No GitLab issues (D-REG-16). Four slices on `develop`, each green on its own:

| Slice | Plan task | Content |
|---|---|---|
| B0 Foundation | Task 30 | ADR-0066 accepted; migrations for `registration_entry_conditions`, the pivot, `alternative_registration_type_id`, `registration_entry_condition_assertions`, `policy_bindings`, the two `consents` columns; models, enums, `Data` objects, events; the three new capabilities and the default roles; `PolicyResolver` with `PolicyResolverTest` as the centre of the suite; metrics catalogue, panel, alerts, runbook anchors. **No behaviour change**: every existing test green, unbound policies resolve exactly as today. |
| B1 Entry conditions | Task 31 | Console screens, the type editor card with the live-type warning and the alternative-type pointer, the public form checkboxes, `SubmitRegistration::guardEntryConditions()`, assertions, `RegistrationConsentGiven`, GDPR export and retention, console verification and the worklist filter. |
| B2 Bindings at the purchase paths | Task 32 | `TargetDirectory` and the binding editor; the `registration.consent` slot; the `checkout.consent` context bag and the **two-step checkout**; `binding_context` on `RegistrationSettlementRequested`, the order line and `CheckoutConsentGiven`; `RecordCheckoutConsent` resolving from the context. |
| B3 Re-consent and the door | Task 33 | `require-contract-consent`, the critical-route list, the interstitial's named act and the "Not now" path; `entry_condition_keys` on `RegistrationConfirmed`, the admission stamp, the ticket line, the door control and `conditions_checked`; door verification listener; the two alerts' unit tests. |

B0 and B1 are independent of B2; B3 depends on B1. The slices are written out as Tasks 30–33 of `docs/superpowers/plans/2026-09-21-round-4.md`.

## 17. Out of scope

- Uploading or storing any proof document (§14.8).
- Verified attributes on an account, carried across registrations and brands (§15).
- Price or eligibility enforcement beyond the declaration itself.
- A binding algebra richer than OR-within-a-kind and AND-across-kinds (§14.10) — `binding_group`, for two independent groups on one policy, is a named future column and not a feature.
- A third checkout role or any role column on `policies` (§14.15) — `requires_consent` is the only lever.
- Re-consent for non-contract texts beyond today's `require-consent` alias.
- Translating policy content.
- Binding a policy to anything Konventix does not already model: a venue, a country, an age band, a Community role.
