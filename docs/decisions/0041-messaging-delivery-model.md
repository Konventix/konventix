# ADR-0041: Messaging delivery model — topics, log, suppression, digests

**Status:** accepted

## Context

Slice 13 makes Messaging real: web push (D-MSG-02), digests and quiet hours
(D-MSG-03), the per-brand delivery log and suppression list (ADR-0028), and
the in-app inbox. Three inventory questions were still open recommendations
(D-MSG-05 preference shape, D-MSG-06 preference custody, D-MSG-07 log
bodies), the coalescing semantics were explicitly undesigned, and two SPECs
contradicted each other about who listens to intent events.

Interview decisions (2026-08-16) settled: **topics owned by Messaging**
(D-MSG-05 Option C + D-MSG-06 Option B), **hybrid log bodies** (D-MSG-07
Option C), **30-minute digest windows with 22:00–08:00 deferring quiet
hours**, and a **live inbox over Reverb**.

## Decision

1. **Topics are the unit of preference and policy.** Modules declare topics
   at boot (key, label, default channels, `suppressible`, `digestable`) in a
   Messaging registry, exactly as they already declare templates. A send
   names its topic; preferences are rows per `(recipient, topic, channel)`
   stored **in Messaging** — the module that applies the rule stores it, so
   an unsubscribe has no consistency window. A topic with
   `suppressible: false` (security notices) ignores preferences but still
   respects the mail suppression list: a preference is a choice, a hard
   bounce is a fact about deliverability. A send without a topic behaves as
   today: immediate, mail, no preference gate — existing callers keep
   working unchanged.

2. **Channel fan-out happens at accept time.** `SendMessage` resolves the
   topic's default channels intersected with the recipient's preferences
   (an account-less recipient has no preferences and gets the defaults) and
   creates one envelope per channel. Mail remains the only channel for
   recipients without an account; in-app and push require a recipient ULID
   by construction.

3. **Every accepted send gets a delivery-log row** (`messaging_messages`,
   one per envelope/channel): status
   `queued → sent | failed | suppressed | held`, the typed payload, template
   and topic keys, attempt count, provider message id, and — only for
   template keys flagged `logBodies` at registration (D-MSG-07 hybrid) —
   the rendered subject and body. The flag defaults to off; the invoice
   template is the first to set it. The log is per brand, keyed for the
   D-CPL-01 twelve-month purge, and `MessageDelivered` / `MessageFailed` /
   `MessageSuppressed` become implemented contract events (delivered =
   accepted by the transport; provider callbacks can only refine that
   later).

4. **Suppression is per brand and per channel, keyed by address or
   recipient.** Mail suppression binds to the email address (an invoice
   contact without an account must be suppressible); push and in-app bind
   to the recipient ULID. Hard bounces and complaints suppress; an operator
   with `ManageSuppressionList` lifts. Provider bounce webhooks are a
   follow-up (per-brand transports are plain SMTP today and emit none);
   the list, its guard in `SendMessage`, and the add/lift actions with
   their events land now so the webhook is one listener when a provider
   exists.

5. **Digests and quiet hours act on the held state, never by dropping.**
   A digestable topic's send is written `held` with a `hold_until` at the
   end of its 30-minute window per `(recipient, channel)`; quiet hours
   (default 22:00–08:00 in the recipient's timezone, brand timezone as
   fallback) push `hold_until` to the window's end. A scheduled sweep
   flushes due holds: one held message sends as itself; several coalesce
   into one digest message listing each item's rendered subject, in the
   recipient's locale. `urgent` (D-CNT-08's word, now a field on the send
   request) bypasses both. Held messages are visible in the log as `held` —
   never silently swallowed.

6. **In-app inbox rows are the in-app channel's delivery.** The channel
   writes a notification row (title, body, optional URL, topic, read
   state); the bell reads a deferred prop (lessons-from-lancore #5); one
   generic listener broadcasts `notification.received` on the user's
   private channel over Reverb (ADR-0025) — LanCore's one good messaging
   idea, kept. Rendering-time filtering for inactive modules (D-BATCH-7)
   applies at read, not at send.

7. **Web push per D-MSG-02**: `minishlink/web-push` (direct — the Laravel
   wrapper's convenience layer is the pipeline this module already owns),
   one VAPID key pair per deployment, per-device subscription rows keyed
   `(recipient, endpoint)`, endpoints pruned on 404/410. The push payload
   is a typed value object (title, body, URL, tag) — LanCore's broken
   free-form contract is the counterexample.

8. **Intent events are the listener exception.** D-CNT-08 and Commerce's
   dunning contract deliberately say "Messaging alone decides what this
   means" — for exactly those events (`AnnouncementPublished`,
   `DunningLevelRaised`) the listener lives in Messaging, because the
   emitting module's contract *addresses* Messaging without naming a
   template. The general rule stands for everything else: a module that
   wants a message sends one.

## Consequences

- Both SPEC boundary lines change in this slice: Identity's "owns the
  stated preference" becomes "links to Messaging's preference page", and
  Messaging's "never watches for reasons to send" gains the intent-event
  exception above.
- A new notification kind is one topic declaration plus one template — no
  migration, no settings-page edit (the page renders from the registry),
  no preference column. LanCore's sixteen-boolean matrix is the
  counterexample.
- The delivery log answers "did they get the mail" for support without
  becoming a body archive; GDPR export walks metadata plus the few flagged
  bodies, and the 12-month purge (enforced when Slice 14 lands the
  retention machinery) has one table to sweep plus the inbox.
- Digest coalescing changes observable behavior for digestable topics
  only; nothing existing declares a topic yet, so shipping this changes no
  current mail.
- Quiet hours need a recipient timezone: the recipient read model gains a
  `timezone` column projected from Identity's events, nullable with brand
  fallback.
