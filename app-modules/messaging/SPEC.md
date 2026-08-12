# Messaging — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

Messaging owns the way the platform talks to people: a channel abstraction over
mail, push and in-app notification, the templates rendered per brand, and the
delivery log kept as infrastructure. It absorbs LanCore's Notification and
EmailLog domains. Messaging is deliberately a kernel *service*, not a domain
holding opinions: a module hands it a recipient ULID, a template key and a typed
payload, and gets delivery semantics in return. Nothing about *why* a message is
sent lives here — the module that owns the process decides that. One opinion it
does hold, settled in the decision log Round 6 gate review (D-MSG-03, user
override): **coalescing is Messaging's job.** Modules still send per-change,
exactly as before, but Messaging owns digest windows and quiet hours as
infrastructure and may hold and merge sends rather than dispatching each one
immediately — "thin service" describes what triggers a send, not what happens
between acceptance and delivery.

Using Messaging is not a cross-module coupling in the ADR-0004 sense. No Eloquent
model and no domain state crosses the boundary; the interface is a contract over
scalars and ULIDs, which is exactly what the rule asks for.

## Owns

- **Channel abstraction**: a `MessageChannel` driver contract with mail as the
  first implementation, in-app next, and push after that. Channel selection,
  fallback between channels, and per-brand sender identity — from address,
  reply-to, footer, signing domain.
- **Templates**: template keys registered by the owning module at boot,
  per-brand overrides, localisation per recipient locale, and rendering against
  a typed payload rather than a free-form array (ADR-0009,
  lessons-from-lancore #11).
- **In-app notification inbox**: notification records per recipient with read
  state, exposed to the frontend as a deferred prop rather than on every request
  (lessons-from-lancore #5).
- **Delivery log**: attempts, provider message identifiers, deliveries, bounces
  and complaints, plus the suppression list built from hard bounces and spam
  complaints. Infrastructure, not a domain record — it exists so a support
  question about "did they get the mail" has an answer. The suppression list is
  per brand, not global, because ADR-0028 makes reputation per brand domain: a
  hard bounce on one brand's sending domain says nothing about deliverability on
  another's.
- **The outbound pipeline**: its own queue name, retry and backoff policy, and
  the brand context carried into and restored inside the job (ADR-0010).
- **Rendering context**: the brand read model — name, canonical domain, sender
  identity, theme tokens for mail — and the recipient read model — display name
  and locale — both maintained from other modules' events.

## Does not own

| Nearby concept | Owned by |
|---|---|
| Whether a person wants a given notification | Identity owns the stated preference; Messaging keeps a read model of it and applies it |
| Email address of record | Identity for accounts, Commerce for invoice recipients — Messaging resolves a recipient by ULID at send time |
| The business condition that triggers a message | the module owning the process — Messaging never watches for reasons to send |
| Campaigns, subscriber lists, bulk marketing | Newsletter, behind a Listmonk client contract |
| Legal consent for marketing contact | Compliance holds the consent record; Messaging enforces deliverability from it |
| Chat, presence, direct messages between participants | Community |
| News articles and announcements | Content — Messaging may deliver a notification about one, the article stays there |
| Brand theme tokens and their contrast validation | Platform (ADR-0008) — Messaging consumes tokens for mail rendering |
| Audit trail of who sent what | Compliance, from Messaging's contract events |
| Ticket and badge documents | Admission — Messaging attaches what it is handed |

The boundary that will be tested first: someone will want a listener in
Messaging on `OrderPaid` to send a receipt. That listener belongs in Commerce,
which then calls Messaging. Otherwise Messaging ends up knowing every module's
event catalogue, which is exactly the shape LanCore's god objects took.

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

Most rows below are still a proposal and become binding when the event class
lands; `MessageQueued` has landed and its payload shape below is the binding
one.

| Event | Emitted when | Payload | Status |
|---|---|---|---|
| `MessageQueued` | A message is accepted for delivery | `message_id`, `brand_id`, `recipient_id`, `channel`, `template_key` | implemented |
| `MessageDelivered` | A channel confirms delivery | `message_id`, `channel`, `provider_message_id`, `delivered_at` | proposed |
| `MessageFailed` | An attempt fails and will or will not be retried | `message_id`, `channel`, `failure_code`, `attempt`, `will_retry` | proposed |
| `MessageBounced` | A provider reports a bounce | `message_id`, `recipient_id`, `bounce_type`, `bounced_at` | proposed |
| `MessageSuppressed` | A send is refused before dispatch | `brand_id`, `recipient_id`, `template_key`, `reason_code` | proposed |
| `RecipientSuppressionAdded` | A recipient is added to the suppression list | `recipient_id`, `channel`, `reason_code`, `suppressed_at` | proposed |
| `RecipientSuppressionLifted` | A suppression is removed | `recipient_id`, `channel`, `lifted_at` | proposed |
| `NotificationRead` | An in-app notification is marked read | `notification_id`, `recipient_id`, `read_at` | proposed |

`MessageSuppressed` matters more than it looks: a module that believes it sent a
confirmation needs a way to learn that the platform declined to.

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `UserRegistered` | Identity | Seed the recipient read model — ULID, display name, locale |
| `UserProfileUpdated` | Identity | Keep display name, locale and timezone current for rendering |
| `UserPreferencesUpdated` | Identity | Maintain the notification preference read model that gates sends |
| `UserAnonymized` | Identity | Drop the recipient read model entry |
| `BrandCreated` | Platform | Seed the brand read model — name, canonical domain, sender identity |
| `BrandThemeUpdated` | Platform | Refresh the tokens used to render branded mail |
| `BrandArchived` | Platform | Stop delivery for an archived brand |
| `DataExportCompleted` | Compliance | Notify the subject that the archive is ready |
| `UserDeletionRequested` | Compliance | Purge inbox and log rows through Messaging's own `Gdpr/` handler |

All projections here must be idempotent and replayable: a read model rebuilt
from scratch has to produce the same rendering context (ADR-0004).

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

Proposed catalogue: `ViewMessageLog`, `ViewMessageContent`, `ResendMessage`,
`ManageMessageTemplates`, `ManageSenderIdentity`, `ViewSuppressionList`,
`ManageSuppressionList`. Seeing that a message was sent and reading its rendered
body are separate capabilities, since the body usually contains personal data.

## Activation

Kernel module. Always active for every brand and not gateable: its provider sets
`$gateable = false` and its routes carry no `ensure-module` middleware. A brand
cannot switch off transactional messaging without breaking password resets,
confirmations and legal notices. Two adjacent things *are* configurable as data
and should not be confused with activation: which channels a brand has
configured, and whether an individual template key is enabled for it.

## Open questions

- ~~Does each brand get its own mail transport and credentials, with SPF and
  DKIM on its own domain, or does one shared transport send on behalf of all
  brands?~~ Settled by ADR-0028: each brand carries its own transport
  configuration (or falls back to the application's default mailer when
  unconfigured), resolved from BrandContext at send time. Consequence beyond
  the transport itself: the suppression list (Backlog, see "Owns" above) is
  per brand, not global — a bounce on one brand's domain says nothing about
  another's.
- ~~Does web push ship at release, or are mail plus in-app enough for the
  Phase 2 definition of done?~~ **Decision log Round 6 (D-MSG-02, user
  override):** ships at release — a push `MessageChannel` driver joins mail
  and in-app.
- ~~Digests, batching and quiet hours: a shift plan changing five times in an
  hour should not produce five mails. Is that a Messaging concern or the
  emitting module's?~~ **Decision log Round 6 (D-MSG-03, user override):**
  Messaging's concern — it owns digest windows and quiet hours as
  infrastructure. Modules keep sending per-change; Messaging coalesces.
- ~~How template payloads are versioned when the emitting module changes
  shape — a queued message may render after a deploy that changed its payload
  type.~~ **Decision log Round 7 (D-BATCH-7):** template payload Data classes
  change additively only; a breaking shape change requires a new template
  key, protecting queued messages across deploys.
- ~~How long the delivery log is kept, and whether that retention is
  Messaging's own decision or a rule published by Compliance. **Still open**
  after the Round 6 gate review — pairs with Compliance's own open
  audit-retention question, since both are "how long do we keep an
  infrastructure record" questions that likely want the same answer.~~
  **Decision log Round 7 (D-CPL-01):** retention is a Compliance-published
  rule — delivery log 12 months, audit trail 36 months.
- ~~Whether an in-app notification for a module that is inactive for a brand
  is suppressed at send time or filtered at render time.~~ **Decision log
  Round 7 (D-BATCH-7):** filtered at render time, not suppressed at send.
