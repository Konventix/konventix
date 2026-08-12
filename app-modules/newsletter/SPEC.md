# Newsletter — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

> **Post-release module.** Newsletter is Phase 3 in `docs/ROADMAP.md` —
> deliberately after release scope. Do not start building it earlier. This
> document is provisional; the contract sketched below is the part that must be
> right, and it is not yet written.

## Purpose

Newsletter gives a brand opt-in mailing lists without owning a mailing system.
The module is a **client behind a contract**: Konventix owns the subscription
intent and its provenance, a driver talks to an external newsletter system, and
campaign authoring and sending stay over there. Listmonk is the first driver
and the only one currently planned — but the contract is the load-bearing part
of this module, not the vendor. No Listmonk concept may leak into another
module, into event payloads, or into column names; swapping the provider must
be a driver change, nothing more.

## Owns

- **List** — a brand's mailing list as Konventix knows it, mapped to the
  provider-side list identifier.
- **Subscription** — an address, its status, and where the opt-in came from
  (signup block, registration flow, import).
- **The `NewsletterProvider` contract and its drivers** — subscribe,
  unsubscribe, status lookup, list synchronization. Listmonk is one
  implementation.
- **Synchronization and reconciliation** — the jobs that keep local state and
  provider state agreeing, and the record of when they last did.
- **Signup surfaces** — the subscription form and the block type registered with
  Platform's CMS.

## Does not own

- **Transactional and system email, the channel abstraction, the delivery log** —
  Messaging. Newsletter is marketing opt-in; Messaging is system delivery.
  Neither is routed through the other.
- **Campaign authoring, templates, sending, bounce and complaint processing** —
  the external provider. Deliberately not reimplemented here.
- **Consent records, policy versions, and GDPR export/deletion orchestration** —
  Compliance. Newsletter contributes a `Gdpr/` exporter and honours deletion
  events; where the opt-in consent record itself lives is an open question.
- **The user account and the authoritative email address** — Identity. A
  subscription may exist for an address with no account at all.
- **Articles and news a campaign links to** — Content.
- **Registration data** — Registration. If an opt-in is captured during a
  registration flow, Registration emits, Newsletter listens; Newsletter never
  reads registration models.

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

| Event | Emitted when | Payload |
|---|---|---|
| None yet; see Open questions | | |

No other module currently has a reason to react to a subscription change.
Nothing is published until one does.

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

Both rows are **proposed**. Listeners must be idempotent and replayable
(ADR-0004) and must short-circuit when the feature is off for the brand
(ADR-0003).

| Event | Owning module | Why |
|---|---|---|
| Registration confirmation events (proposed) | Registration | Record an opt-in that was captured during registration |
| User deletion/anonymization events (proposed) | Compliance | Remove the subscription locally and at the provider |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

Not yet defined. Expect an administrative catalog around managing lists,
viewing subscriptions, and triggering synchronization. Subscribing and
unsubscribing are public actions and need no capability.

## Activation

Pennant feature: **`newsletter`**. Registration happens at boot regardless; the
feature gates routes, navigation, UI slots, and listeners (ADR-0003).

With the feature off, a brand has no signup form or block, no list
administration, and no provider synchronization. Subscriptions that already
exist in the external system are not touched — Konventix simply stops managing
them, which is a behaviour to confirm rather than assume (see open questions).

## Open questions

- Where the opt-in consent record lives: Compliance (consistent with its
  ownership of consent) or here, next to the subscription. This needs settling
  before any schema.
- Double opt-in: whether the confirmation flow runs in Konventix or is delegated
  to the provider, and which side owns the confirmation timestamp for proof.
- Reconciliation policy when local and provider state disagree — which side
  wins, and what is logged.
- Whether the provider is per operator, per brand, or per list.
- What happens to provider-side subscriptions when the feature is disabled for a
  brand or the module is uninstalled.
- Whether an in-process fallback driver (send through Messaging) is worth having
  for self-hosters who do not want a second service.
