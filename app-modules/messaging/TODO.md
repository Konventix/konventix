# Messaging — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

Phase 3 candidate: wire a real caller. Nothing in the platform calls
`SendMessage` yet — Identity's password reset / registration confirmation is
the natural first consumer and would register the first real template.

- [ ] Backfill for accounts created before the recipient projection existed:
      the read model only sees events from now on; a `messaging:rebuild-recipients`
      path (or an Identity replay) is needed before relying on
      id-only sends for old accounts. `UserAnonymized` is also still
      unconsumed — it lands with Identity's GDPR handler.
- [ ] Suppression-list and message-log operator screens: `AddSuppression`/
      `LiftSuppression` exist with `ViewSuppressionList`/`ManageSuppressionList`
      catalogued, but lifting means tinker; the delivery log likewise has no
      `/admin` view yet (`ViewMessageLog`/`ViewMessageContent`).
- [ ] Provider bounce/complaint webhooks feeding `AddSuppression` (ADR-0041
      point 4 follow-up): per-brand transports are plain SMTP today and emit
      none, so only the seam exists. `MessageBounced` in SPEC.md stays
      proposed until this lands.
- [ ] A brand read model — name, canonical domain, theme tokens for mail —
      maintained from `BrandCreated` / `BrandThemeUpdated` / `BrandArchived`
      (SPEC.md "Consumes"). Sender identity and transport do **not** need this:
      ADR-0028 makes them Messaging-owned config data
      (`messaging_brand_settings`), operator-managed until a settings UI
      exists, not a projection of Platform events — this item is now only
      about the rendering-context fields (domain, theme tokens), narrower than
      originally scoped.
- [ ] Fix `tests/Unit/ArchitectureTest.php`'s "never imports another module
      Eloquent model" check (root test, out of `app-modules/messaging/`
      scope): it flags `use Konventix\Platform\Models\Concerns\BelongsToBrand;`
      as a foreign-model import in every module but Platform, because its
      allow-list only exempts `Models\Brand`, `Models\Role`, `Models\Permission`
      by exact class, not the `Models\Concerns\*` traits ADR-0010 requires
      every brand-owned model to use. Pre-existing before this change (already
      true for `event` and `compliance`'s models); this module's
      `BrandMailSettings` and `TemplateOverride` add two more instances of the
      same, correct, pattern. Needs a fix in the shared test, not in any
      individual module.

## Backlog

- [ ] Push end-to-end against a real browser: the channel, worker and
      subscription flow are tested on fakes; a manual pass against Chrome and
      Firefox (permission prompt, delivery with the site closed, endpoint
      rotation) still needs doing once a deployment has VAPID keys.
- [ ] Recipient timezone is never populated: Identity's contract events carry
      no timezone field, so quiet hours always fall back to the brand's
      Messaging settings (or the app timezone) today. Needs
      `UserProfileUpdated` (or a dedicated event) to gain the field, plus
      `ProjectRecipient` projecting it.
- [ ] Held messages with attachments are deliberately not held (attachments
      are referenced on the envelope but not persisted on the log row, so a
      flush would lose them). If a digestable topic ever legitimately carries
      attachments, the log row needs to store the references.
- [ ] D-BATCH-7 render-time filtering for inactive modules in the inbox: the
      read endpoints do not yet skip notifications whose topic belongs to a
      module inactive for the brand — harmless today because every module
      that declares topics is a kernel/core module (always active), but the
      filter needs module attribution on topics before a gateable module
      declares one.
- [ ] Recipient and preference read models from Identity events — idempotent
      and replayable, rebuildable from scratch. (Superset of the recipient
      read model item under Next above; that item is the minimum slice —
      email/locale — needed to drop the temporary `SendMessageRequest` fields.)
- [ ] Message log view registered in Platform's navigation
      (`ViewMessageLog`/`ViewMessageContent`/`ResendMessage` from
      `src/Enums/Permission.php`, done — see Done below), plus the
      `src/Gdpr/` handler that exports and purges inbox and log rows
      (`ManageMessageTemplates`/`ManageSenderIdentity`/`ViewSuppressionList`/
      `ManageSuppressionList` are the remaining capabilities with no UI yet
      either).
- [ ] `permissions:sync` (ADR-0013) doesn't exist in the codebase yet — once it
      does, run it so `src/Enums/Permission.php`'s seven cases get rows in the
      `permissions` table. Not specific to this module, but noted here since
      this is the first module (with Compliance) to populate a real
      `Permission` enum for it to sync.
- [ ] Consider whether `BrandCreated` should auto-seed an "unconfigured"
      `messaging_brand_settings` row per brand (so every brand always has
      exactly one row) versus the current design, where absence of a row
      *means* unconfigured and `MailChannel` already handles that by falling
      back to the default mailer. No behavioural gap today — this is a
      query-ergonomics question, not a correctness one.

## Done

- [x] Web push (Slice 13d, ADR-0041 point 7, closes D-MSG-02):
      `PushChannel` delivers the typed `PushMessageData` (title, plain-text
      body, URL, tag) to every `PushSubscription` of the recipient through
      the `PushSender` seam (`WebPushSender` wraps minishlink/web-push
      directly; tests run on a fake). Endpoints reported gone (404/410) are
      pruned on the spot; no devices is a no-op, and the send fails only
      when every surviving endpoint refused. One VAPID key pair per
      deployment (`services.webpush`, generated by `messaging:vapid-keys` —
      rotation invalidates all subscriptions). The static `public/push-sw.js`
      worker renders the payload and opens the URL on click; the
      notification settings page owns the per-device enable/disable flow
      against `POST/DELETE /push-subscriptions` (re-subscribing an endpoint
      refreshes its keys).
- [x] In-app inbox (Slice 13c, ADR-0041 point 6): `InAppChannel` delivers by
      writing the `InboxNotification` row (rendered title/body, optional
      `url` taken from a string `url` key in the payload, topic, read state)
      and pushing `NotificationReceived` (`notification.received`,
      `ShouldBroadcastNow`) on the recipient's private Reverb channel — the
      one `routes/channels.php` already authorizes. Bell
      (`messaging/NotificationBell`, mounted in `AppSidebarHeader` via the
      module component registry so the shell holds no module import) fetches
      `/notifications` JSON once per browser page load and listens over
      Echo; mark-read / mark-all-read endpoints call
      `MarkNotificationRead`/`MarkAllNotificationsRead`, which emit
      `NotificationRead` on first read only. `NotificationData` is the
      ADR-0009 single shape for fetched rows and broadcast payloads alike.
- [x] Digest windows and quiet hours (Slice 13b, ADR-0041 point 5, closes
      D-MSG-03): `HoldPolicy` writes a digestable topic's non-urgent send as
      `held` with `hold_until` at the end of its 30-minute window per
      (brand, recipient, channel) — later sends join the open window — and
      quiet hours (22:00–08:00, recipient timezone → brand Messaging
      settings → app timezone) push the end to 08:00. `FlushHeldMessages`
      (scheduled `messaging:flush-held`, every minute, row locks against
      overlapping sweeps) sends a single due hold as itself (payload
      rehydrated from the log row via `RehydratedPayload`,
      `recipient_locale` now stored for exactly this) and coalesces several
      into one `messaging.digest` message (`DigestData`: each member's
      subject rendered in the recipient's locale); members are marked sent
      with `digest_message_id`. Urgent and attachment-carrying sends bypass
      holding.
- [x] Topics, fan-out, delivery log, suppression and preferences (Slice 13a,
      ADR-0041 points 1–4): `TopicRegistry` + `Topic` declared via the new
      `ModuleServiceProvider::registerMessageTopics()` hook; `SendMessage` is
      now the fan-out point — one envelope and one `messaging_messages` log
      row per resolved channel (topic defaults, minus channels without a
      driver or that need an account the recipient lacks), with preference
      and suppression refusals logged `suppressed` + `MessageSuppressed`,
      never silent. `SendQueuedMessage` keeps the log row (`sent`/`failed`,
      attempts, last error) and emits `MessageDelivered`/`MessageFailed`;
      bodies stored only for `logBodies`-flagged templates (D-MSG-07 hybrid).
      Per-brand `Suppression` with partial-unique live rows,
      `AddSuppression`/`LiftSuppression` +
      `RecipientSuppressionAdded`/`Lifted`. `NotificationPreference` rows per
      (recipient, topic, channel) with the `settings/notifications` page
      rendered from the registry (non-suppressible topics render always-on,
      forged toggles ignored). `messaging_recipients.timezone` added
      (nullable, awaits an Identity payload) for quiet hours. A send without
      a topic behaves exactly as before — immediate, mail, no preference
      gate.
- [x] Recipient read model (Slice 2): `messaging_recipients` keyed by the
      user's own ULID (a projection, not an entity — no generated key, not
      brand-scoped per ADR-0016), projected idempotently by
      `Listeners\ProjectRecipient` from Identity's new `UserRegistered` /
      `UserProfileUpdated` contract events. `SendMessage` now resolves
      email/locale from `recipientId` alone; the explicit
      `recipientEmail`/`recipientLocale` fields stayed by design (no longer
      temporary) for account-less recipients (billing contacts,
      `recipientId: null`) and deliberate address overrides. A send that
      resolves to no address is refused loudly.
- [x] Attachments + explicit brand (Slice 2): `AttachmentData`
      (disk/path/as/mime — referenced, not inlined, so job payloads stay
      small) carried through `SendMessageRequest` → envelope →
      `TemplatedMail::attachments()`. `SendMessageRequest.brandId` overrides
      ambient BrandContext for operator/queue-triggered sends; `MailChannel`
      and `TemplateRenderer` now key transport, sender identity and brand
      overrides off the envelope's own `brandId` instead of ambient context
      (the old context-guarded lookup silently fell back to the default
      mailer on brand-less workers).
- [x] First real consumer (Slice 2, closes the exhibitor DoD item):
      Commerce registers `commerce.invoice-issued` (de default + en) via the
      new `ModuleServiceProvider::registerMessageTemplates()` hook and mails
      the sealed ZUGFeRD archive to the billing party from
      `GenerateInvoiceDocument` (`SendInvoiceMail`, replay-safe behind the
      document seal). The root architecture test now sanctions Messaging's
      declared send API surface (`Actions`/`Data`/`Templates`) for other
      modules — models stay forbidden.

- [x] `MessageChannel` driver contract (`src/Contracts/MessageChannel.php`)
      with a mail driver (`src/Channels/MailChannel.php`) as first
      implementation. The send API modules consume is
      `Konventix\Messaging\Actions\SendMessage::handle(SendMessageRequest
      $request)`: recipient ULID, template key, typed
      `spatie/laravel-data` payload — no Eloquent models, no free-form arrays
      (ADR-0009). Type-level enforcement (a plain PHP `Data` type-hint) is
      covered in `tests/SendMessageActionTest.php`.
- [x] Per-brand sender identity and transport (ADR-0028): `BrandMailSettings`
      (`messaging_brand_settings` table, module-owned, keyed by `brand_id`) —
      from-name, from-address, reply-to, plus `transport` under an
      `encrypted:array` cast. `MailChannel` resolves it from `BrandContext` at
      send time and falls back to `config('mail.default')` when a brand has no
      row. Design note: builds a runtime-registered, uniquely-named mailer
      (`config(['mail.mailers.<name>' => $transport])` + `Mail::mailer($name)`)
      rather than `Mail::build($transport)` — both are "mailer on the fly" in
      Laravel's terms, but this form stays compatible with `Mail::fake()` in
      tests (its fake manager implements `Factory::mailer()`, not `build()`),
      letting tests assert the resolved mailer name to prove per-brand
      transport selection.
- [x] Template registry (`src/Templates/TemplateRegistry.php`), registered as
      a singleton the same way Platform registers `NavigationRegistry`;
      modules call `TemplateRegistry::register()` from their own
      ServiceProvider's boot (no module does yet — see Next). Per-brand
      overrides: `TemplateOverride` (`messaging_template_overrides` table,
      unique on brand + template key + locale, subject/body individually
      nullable so a brand can override just one). Localisation: recipient
      locale if the template (or override) defines it, else the template's
      declared default locale. Rendering: `Blade::render()` against a string
      — deliberately not a compiled `.blade.php` view, since a brand's
      override is a database row, not a deploy.
- [x] Outbound pipeline: `SendQueuedMessage` (`src/Jobs/`) on its own
      `messaging` queue (set in the job's own constructor, so every dispatch
      site gets it for free), 3 attempts with 10s/60s/300s backoff
      (documented in the class docblock). Brand context: relies entirely on
      Platform's `PropagatesBrandContextToQueue`, not duplicated here.
      `MessageQueued` (`src/Events/`, scalar payload: `messageId`, `brandId`,
      `recipientId`, `channel`, `templateKey`) fires from `SendMessage` when
      the job is accepted, not from the job itself.
- [x] `src/Enums/Permission.php`: `ViewMessageLog`, `ViewMessageContent`,
      `ResendMessage`, `ManageMessageTemplates`, `ManageSenderIdentity`,
      `ViewSuppressionList`, `ManageSuppressionList` — catalogue only, no
      `permissions:sync` run yet (see Backlog), no policies/routes/nav
      consuming them yet (nothing to gate: this module ships no controllers).
