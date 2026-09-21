# ADR-0066: A policy binds to what it governs, and a registration type may demand a declaration

**Status:** accepted (2026-09-21, operator interview on the design at
`docs/superpowers/specs/2026-09-21-entry-conditions-and-policy-bindings-design.md`;
all twenty-five decision points of its §14 answered and recorded as Round 10 of
`docs/inventory/decision-log.md` — D-REG-17…24, D-ADM-06/07, D-CPL-02…17; built
as Tasks 30–33 of `docs/superpowers/plans/2026-09-21-round-4.md`). Extends
ADR-0005 and the consent model of #2, #76, #200 and #205; narrows ADR-0060.

## Context

Two facts about the platform collide with how an operator actually sells.

A policy is brand-wide or operator-wide. `Policy::resolveFor()` answers one
question — which text does *this brand* read under this slug — and the
checkout knows exactly two roles, `terms` and `withdrawal`
(`CheckoutLegalTexts::SLUGS`, #200). There is no way to say "these terms
apply to the student ticket", "this addendum applies when you pay through
Stripe", or "these house rules apply to the summer event and to nothing
else". An operator who needs one of those today has two options, and both
are wrong: publish the text brand-wide and make every payer accept terms
that do not concern them, or publish nothing and have no record at all.

A `RegistrationType` has explicit axes (ADR-0005) for visibility, approval,
settlement, confirmation and grants — and no vocabulary for the one thing a
reduced fare rests on: that the buyer meets a condition. "Student ticket" is
a word in a type's name today. Nobody asserts anything, nothing is recorded,
and the door has no idea it should ask for an ID. The three things an
operator needs from such a fare — the declaration, the evidence, and the
check at the entrance — do not exist in any module.

The third ask is the same shape seen from the other end. When a new version
of a contract text is published, the people already registered have agreed
to the old one. #205 shipped half of the answer: `lawful_basis = contract`,
`OutstandingConsents::subjectHasOutstandingContract()`, a session key at
login and a flag on the checkout slot. The other half — a running session,
before the next act that creates or changes a contract — is still the opt-in
`require-consent` route alias, which asks the *wider* question and is
applied to two route groups in the whole codebase.

All three are one sentence: **a legal text should apply to exactly what it
governs, and be asked for exactly when it binds.**

## Decision

**A policy may declare what it governs; a registration type may demand a
declaration; and a contract text re-asks at the acts it binds.**

1. **`policy_bindings`** (Compliance) — `policy_id`, `kind`, `target_key`.
   `kind` is one of `event`, `registration_type`, `settlement_provider`,
   `entry_condition`; `target_key` is a ULID for the three entity kinds and
   the provider's own string key (`stripe`, `mollie`, `unzer`, `manual`) for
   the fourth, because a settlement provider is a key on
   `settlement_accounts`, not an entity. A binding hangs on the **policy**,
   never on a version: rebinding a text is not a new version of it.

2. **A policy with no bindings keeps today's behaviour exactly** — it applies
   everywhere its brand sells (D-CPL-04). A policy with bindings applies where
   its bindings match the context at hand, combining as **OR within a kind and
   AND across kinds** (D-CPL-03): several events are alternatives, but a policy
   bound to an event *and* to a provider applies only where both hold, so "our
   Stripe addendum, but only at the big event" is expressible with two bindings
   on one policy. That rule is narrower than this model's usual "resolve
   towards asking", so it is written in words on the console card where
   bindings are made: *"applies when any of the events match and any of the
   types match and any of the conditions match and any of the providers
   match."* The migration adds a table and changes the meaning of no existing
   row; the three placeholders `konventix:install` seeds, and #200's two
   checkout roles, are untouched — there is no third role and no role column,
   because a version's `requires_consent` alone decides whether a matching text
   is agreed to or merely shown (D-CPL-08).

3. **Compliance alone resolves policies.** Consumers hand it a
   `BindingContext` of scalars — brand, event, registration types, entry
   conditions, settlement provider, and the registration or order the
   resolution belongs to — through the UI slot they already use
   (`checkout.consent`, and a new `registration.consent` declared by
   Registration). `SlotRegistry::for()` has taken a context bag since it was
   written; this is the first caller to use it. Commerce carries the
   registration-side scalars as an **opaque bag** it never interprets,
   handed to it on `RegistrationSettlementRequested` and stored on the order
   line, with no backfill for orders that already exist (D-CPL-06). No module
   outside Compliance learns that a `Policy` row exists, and Compliance learns
   nothing about a `RegistrationType` beyond its ULID (ADR-0004). Because the
   list of texts depends on the settlement provider, **checkout becomes two
   steps** (D-CPL-07): the payer chooses the provider, and a confirmation step
   then lists exactly the texts that apply to that purchase through that
   provider and carries the accept control. A brand with a single settlement
   account goes straight to the confirmation step.

4. **`registration_entry_conditions`** (Registration) — a first-class,
   brand-scoped row with a stable key, a name, the **verbatim sentence the
   registrant ticks**, a document hint, and how it is verified
   (`self_declared`, `document_at_door`, `operator_checked`). Types declare
   conditions through a pivot; a submission writes one
   `registration_entry_condition_assertions` row per condition, carrying a
   **snapshot** of the sentence shown, the asserting subject, and a state
   (`asserted`, `verified`, `refused`, `waived`). The snapshot is to an
   assertion what a version id is to a consent: evidence may not change
   under the person who gave it. Because the evidence is the snapshot and not
   the pivot, the set of conditions on a type stays **editable after the type
   has sold** (D-REG-19): existing registrations keep their snapshots, a
   condition added later is *not asserted* for them and is shown as such
   rather than invented for them, and a condition removed later keeps its
   historic assertions. One assertion is written per registration, whatever
   its quantity (D-REG-20).

5. **No proof document is ever stored.** A `document_at_door` condition
   rides to the entrance as a label on the admission, the offline client
   asks door staff to look at a physical ID, and the scan records an
   outcome, queued like every other offline write (D-ADM-06). A refusal
   records a bounded reason code and **does not revoke the admission** — the
   door decides access, never money (ADR-0005). The label is visible to the
   door and to the operator console, the **document hint** prints on the
   ticket PDF, and **nothing of this reaches the badge** (D-ADM-07): the
   holder needs to know what to bring, the room does not need to know what
   they paid. `operator_checked` is advisory and fills a worklist; a check
   that must block confirmation is `approval: required` on the type
   (D-REG-22).

6. **A consent record names the binding it was given under**: the binding id
   and a snapshot of the context the resolution ran on. "Which text, which
   version, and why this person was asked" becomes one row.

7. **Re-consent to a contract** keeps `lawful_basis = contract` as its flag —
   no second, disagreeable marker — and gains one middleware alias,
   `require-contract-consent`, registered by Compliance and applied by the
   owning module to the routes that create or change a contract, beside the
   existing `require-consent`. It is never applied to the policy pages, the
   login routes, the account's own read surfaces, or Support's report channel
   (ADR-0063): a gate in front of the channel by which someone reports the
   gate is broken is the worst loop this platform could build. It carries only
   the acts that create or change a contract (D-CPL-13), the interstitial stays
   `policies.required` with one line naming the act (D-CPL-15), and it offers a
   **"Not now"** way out whose refusals are measured by deriving grants from
   prompts rather than by a counter of its own (D-CPL-17).

8. **Over-binding over-asks.** Every ambiguity in resolution is settled
   towards showing one text too many. A text shown needlessly is an
   annoyance; a text not shown is an unenforced legal requirement. The one
   deliberate exception is the AND across kinds of point 2, taken knowingly in
   exchange for expressing the narrow case at all.

9. **Binding is its own privilege.** Compliance gains `ManagePolicyBindings`,
   separate from `ManagePolicies` — binding a text to a product changes who is
   legally bound by it without changing a word of it — and Registration gains
   `ManageEntryConditions` and `VerifyEntryConditions`, because defining a fare
   condition and judging a document at the door are different jobs (D-CPL-16,
   D-REG-24).

## Consequences

- Compliance gains `policy_bindings`, two nullable columns on `consents`
  (`policy_binding_id`, `binding_context`), a `PolicyResolver` that
  generalises `CheckoutLegalTexts::forBrand()`, a second middleware alias,
  and its **first** `src/Metrics/` catalogue (ADR-0059): consent prompts,
  grants, withdrawals, binding matches, and an alert for a gate that asks
  everyone and convinces nobody.
- `CheckoutLegalTexts::forBrand()` stays as the brand-wide, contextless call
  — it is what #222's gap notice and `missingSlugsForBrands()` ask, and those
  questions do not change. What it is no longer is *the whole answer* at a
  checkout.
- `RecordCheckoutConsent` must resolve from the context the page rendered,
  carried on `CheckoutConsentGiven`, rather than re-deriving it from the
  brand. Otherwise the rows written and the texts shown can differ, which is
  the one bug in this area that would look like evidence and be none.
- The pay page and the cart checkout gain a **confirmation step**: the
  provider is a recorded choice *before* any legal text is shown, and the texts
  are resolved for that provider. Going back to change the provider re-resolves
  the list and clears the acceptance. A payer is never held to a list they did
  not see.
- Registration gains three tables, a nullable `alternative_registration_type_id`
  on `registration_types` (the "buy the regular ticket instead" link offered
  when a declaration is refused, D-REG-21), two capabilities, a console screen,
  a card in the type editor, and one more per-type guard in
  `SubmitRegistration` beside `guardBillingParty()`. `entryConditions`
  deliberately does **not** join ADR-0060's frozen axes: an operator may add or
  remove a condition on a live type, and the console — not a migration and not
  a retroactive assertion — carries the truth that older registrations never
  saw it.
- Admission stamps an opaque `{key, label, verification, document_hint}` list
  on the admissions it issues, prints name and hint on the **ticket** and
  nothing on the **badge**, and returns `conditions_checked` on a scan. It reads
  no Registration table and learns of no assertion. The PII-free delta feed
  stays PII-free; the label rides on the admission payload the door already
  downloads for its own holders.
- Platform gains a small `TargetDirectory` registry so the binding console can
  *label* an event, a type, a condition or a provider without Compliance
  reading a foreign model — the same shape as `PublicLegalTexts` (#184):
  Platform declares, the owning modules contribute, the reader never knows
  who answered.
- An entry-condition assertion is personal data with a purpose — pricing a
  ticket and checking it at the door. It is exported and anonymized with the
  registration, kept on the registration's own retention clock and never on the
  ten-year bookkeeping floor (D-REG-23), and must never reach an attendee list
  (ADR-0064), a public profile, or a metric label.
- `tests/Unit/ArchitectureTest.php` must stay green with **no new
  cross-module import in either direction**. That assertion, and
  `PolicyResolverTest`'s "an unbound policy resolves exactly as it does
  today", are the two tests this ADR lives or dies by.
