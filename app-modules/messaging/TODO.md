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

- [ ] Push `MessageChannel` driver (decision log Round 6, D-MSG-02, user
      override — new release scope): joins mail and in-app; needs subscription
      storage per recipient device and a provider client behind the same
      channel contract `MailChannel` already implements.
- [ ] Digest windows and quiet-hours infrastructure (decision log Round 6,
      D-MSG-03, user override — new release scope): Messaging holds and
      coalesces per-change sends from other modules into a digest per
      recipient/window rather than dispatching each one immediately; quiet
      hours suppress/delay delivery per recipient. Design the coalescing key
      (recipient + template family?) before building the queue changes this
      needs on top of `SendQueuedMessage`.
- [ ] In-app inbox with read state, exposed as a deferred prop rather than on
      every request (lessons-from-lancore #5). ADR-0041 point 6: the in-app
      channel writes the notification row; `notification.received` broadcasts
      on the user's private Reverb channel.
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
