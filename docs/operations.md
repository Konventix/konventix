# Operations

What an operator looks at and does when Konventix misbehaves. Alerts come
from `contrib/observability/alerts/konventix.rules.yaml` (ADR-0059); each
section below is the runbook anchor the rule's annotation names.

## Dashboards

Grafana folder "Konventix": `konventix-sale` (orders, payments, webhooks,
waiting room, messages, seating), `konventix-platform` (HTTP, queues, health,
runtime, database), `konventix-logs` (errors, request records, one
correlation id). Dashboards are generated from
`contrib/observability/src/`; an edit in Grafana is reverted on the next
resync.

## Finding a purchase

Every response carries `X-Request-ID`; error pages show it as "Reference";
a user can quote it. Paste it into the Logs dashboard's "Correlation id"
box. The panel shows the ingress access line (the id is its last field),
the `http.request` record with route, status and duration, every record
the request wrote, and the `queue.job` records of the jobs it dispatched,
in time order.

## Triggering each alert once

Acceptance for #168: every alert fired on purpose and received. Record
date, alert names and recipient under the alert's section when done.

- `kubectl -n konventix scale deploy konventix-horizon --replicas=0`, wait
  ten minutes: KonventixHealthCheckCritical (Horizon), KonventixRoleUnavailable,
  and once jobs queue up, KonventixQueueBacklog. Scale back to 1.
- `kubectl -n konventix scale deploy konventix-scheduler --replicas=0`,
  wait ten minutes: KonventixScheduleStale, then KonventixHealthCheckCritical
  (Schedule). Scale back.
- `kubectl -n konventix exec deploy/konventix-web -- php artisan tinker
  --execute 'Log::error("alert probe")'`: KonventixErrorsLogged within five
  minutes.
- KonventixDatabaseBackupMissing fires on its own on an instance that never
  completed a backup.

The other thirteen — KonventixMetricsDown, KonventixHealthCheckFailed,
KonventixQueueJobsFailing, KonventixWaitingRoomStuck, KonventixWebhookSilence,
KonventixPaymentFailureRate, KonventixHttp5xxRate, KonventixCheckoutSlow,
KonventixWalArchivingFailing, KonventixPodsRestarting,
KonventixResourceHoldSweepStale, KonventixClaimInviteMailFailing and
KonventixMailQuotaExhausted — are not triggered
by hand for #168; each has a `vmalert-tool` unit test in
`contrib/observability/alerts/tests/konventix.rules.test.yaml` that shapes an
input series so the rule's expression fires and asserts the labels vmalert
reports, which is the acceptance in place of a live trigger. Two of those thirteen
need more than the unit test to prove the *metric*, not just the rule, is
honest: KonventixWebhookSilence and KonventixPaymentFailureRate are proven by
running a sandbox payment flow — a Stripe test-mode webhook withheld, and a
run of declined test cards. KonventixWalArchivingFailing and
KonventixPodsRestarting are platform-side, sourced from CNPG's and kubelet's
own exporters rather than anything this application emits, so they are
observed when they occur in the cluster rather than provoked.

## A console page answers 403 to the operator admin

Capabilities are per scope: on the admin host only roles of the *operator*
scope count, and the one that exists is "Operator Admin". Since 0.6.3
`permissions:sync` (the migrate hook runs it on every deploy) re-grants that
role every capability the release declares, unless the operator has narrowed
it (`roles.customized_at`). On an instance where a page still 403s, run
`php artisan permissions:sync` once and reload; a role the operator narrowed
on purpose is reported as `skipped` and has to be widened by hand. Roles
granted on a *brand* do not apply on the admin host — that is ADR-0029, not
a bug.

## Closing sign-up

Operator settings in the console, "Let visitors create accounts": off, the
sign-up form and the Steam sign-up step send a stranger to the login page
with a note that sign-up is closed, and the login page drops its "Sign up"
link. Existing accounts log in as before. `SIGNUPS_ENABLED=false` in the environment (the chart's
`app.signups.enabled`) closes sign-up for the whole instance whatever the
switch says — the console then shows why beside the switch — which is what a
test system on a public host wants.

## Opening seating on an event

Order matters, because a registration only grows a seat claim at the moment
it is confirmed:

1. Create the seat categories under Categories in the operator console.
2. Give each category its own quota under Quotas, sized to the number of
   bookable places, and point the quota's Category field at it. Blocking a
   place or reserving one for the organisation then takes a unit off the
   sale the way a purchase does, and giving either back offers the unit to
   the waitlist.
3. Set the seating grant on every entry under Registration types that should
   come with a place, and pick the category it grants.
4. Import or draw the hall plan under Seating plans and publish it.

Unpublishing a plan to edit it does not stop the category selling. The quota
is untouched, registrations still sell and confirm, and their seat claims wait
`unplaced` until the plan is published again, at which point the picker serves
them.

**Cutover hand-over (Eventula import):** step 3 has to happen before the
Eventula import runs. The importer carries users, events and orders only --
it does not carry seating -- and a registration that was already confirmed
when its type had no seating grant gets no claim, so those participants never
see the picker. There is no backfill command: a claim is only created when a
registration is confirmed or amended, so an already-confirmed registration on
a type that gained the grant afterwards needs an amendment adding that grant.
The amendment id keys the claim, so replaying the same amendment adds
nothing.

## Handing a participant their ticket

A ticket is self-service: the buyer downloads every admission their
registration granted from `/my/registrations/{registration}/ticket`, and
anyone who has claimed an admission downloads their own from `/my/tickets`.
There is no admin route that generates a participant's ticket on an
operator's behalf — that document is deliberately participant-facing only
(ADR-0061). When someone at a desk cannot self-serve (no account, a device
that will not open the mail), the operator equivalent is the **badge**, not
the ticket: `GET /admin/admissions/{admission}/badge` (`BadgeController`,
behind `admission.print-badge`) prints the physical identity document
check-in already reads, from the same KVX1 token. Sending someone to the
door with a printed badge and no ticket is normal; the two documents carry
the same scannable code.

An admission with no linked holder account (`holder_user_id` is null) has no
row in anyone's `/my/tickets` — group and B2B bookings look like this until
a claim link is used. The buyer's own
`/my/registrations/{registration}/ticket` still lists every admission the
registration granted, holder or not, so a buyer can always retrieve every
ticket their registration paid for, claimed or otherwise.

### A superseded-nonce refusal at the door

`CheckInRejectionReason::NonceSuperseded` means the presented code is real
but is not the admission's *current* one — someone is scanning an older
printout or QR after the admission's token was rotated
(`IssueAdmissionToken::rotate()`, run only on a deliberate reissue, never on
an ordinary download). The fix is always the same: the holder needs their
current ticket, downloaded fresh from `/my/tickets` or reprinted as a badge
from the console. This is not paged — the observability task deliberately
adds no alert for it (ADR-0059 point 8), because a refusal is already
visible to the person standing at the door — but
`admission_tokens_superseded_total` on the Sale dashboard's "Tickets and
calendars" row is what an operator watches during a sale: a steady trickle
is reissues behaving as designed, a spike against one event is worth asking
about.

### Backfilling the ticket's read models

Two commands, safe to re-run, neither one specific to a single event:

- `vendor/bin/sail artisan registration:announce-ownership` — re-emits the
  narrow `RegistrationOwnershipAnnounced` event for every existing
  registration, so `admissions.buyer_user_id` is populated for admissions
  issued before this feature shipped. Never re-sends a confirmation mail:
  that is exactly why this is its own event and not a replay of
  `RegistrationConfirmed`.
- `vendor/bin/sail artisan event:replay-details` — re-emits
  `EventRescheduled`, `EventDetailsUpdated` and, since this feature,
  `VenueAssigned` for each event's primary venue, so Admission's
  `admission_event_summaries` and Registration's
  `registration_event_summaries` both pick up a name, a schedule and a
  venue for events created before the two read models existed. Run it again
  after backfilling a venue onto an event that predates this deploy — the
  command does not run itself. Since #292 the same run also carries each
  event's `banner_media_id` into both summaries.
- `vendor/bin/sail artisan admission:announce-ownership` — re-emits the
  narrow `AdmissionOwnershipAnnounced` event for every admission (#292), so
  Resources' `resource_admissions` read model — who holds and who bought
  each ticket, which the per-ticket seat picker authorises on — is
  populated for admissions issued before that table existed. Only a
  projection listens; never a replay of `AdmissionIssued`, which would
  count every admission on the dashboards a second time. Run it once after
  the deploy that brings the table, after `event:replay-details`.

### Backfilling the media library's variants — the post-deploy step for 0.7.0

A variant is cut when a picture is uploaded, so every picture already in a
brand's library predates any variant invented afterwards. `ticket_banner`
(0.7.0) is the first such case: without it the ticket PDF falls back to the
original and base64-inlines the whole picture on every page, which is what
made a multi-page ticket slow. Run it once after the 0.7.0 deploy:

```
vendor/bin/sail artisan media:regenerate-variants
```

It walks the library across every brand — a console run resolves no brand
(ADR-0029), so the walk deliberately escapes the brand scope and rewrites
each row in the brand it already belongs to; it creates nothing. It is safe
to re-run: by default only the variants an item is actually missing are
cut, and a row whose map does not change is not touched at all, so a second
pass reports "Nothing to backfill" and writes no bytes. A picture too small
for a variant gets none and is skipped on every later pass too —
`ThumbnailGenerator` never upscales, and the consumers fall back to the
original.

`--brand=<ulid|slug>` narrows the walk to one brand, which is how to spread
a large library over several runs. `--only=thumb,ticket_banner` narrows it
to named variants — the flag is validated against what the generator knows,
so a typo stops the run instead of quietly backfilling nothing.
`--missing=false` re-cuts variants an item already has; that is a rewrite,
not a backfill, and it invalidates whatever a reader has cached, so it is
for a changed generator and nothing else.

## Mail quota

The default mail transport sits behind a mailbox provider that refuses
mail past a fixed number of recipients per hour and per day — netcup's
webhosting allows 100 an hour and 500 a day (forum.netcup.de, read
2026-09-21) — and a burst used to hit that wall on the transport and fail.
Since the 2026-09-21 bugfix round Messaging counts what it sends per
budget and window (`Mail\MailQuota`, `config/messaging.php`,
`MAIL_QUOTA_PER_HOUR` / `MAIL_QUOTA_PER_DAY`) and holds a non-urgent mail
that would cross either limit: the delivery log shows it `held` with
`hold_reason = mail_quota` and a `hold_until` at the start of the next
free window — the next UTC clock hour, or the budget's next midnight —
and the every-minute `messaging:flush-held` sweep releases it, checking
the quota again as it does so an exhausted window re-holds instead of
bursting. Nothing is dropped; in-app and push rows are not mail and never
wait; an urgent send (a console resend, an urgent announcement) is never
held but still counts.

Budgets follow the account the provider is counting: a brand with its own
SMTP transport has its own hourly and daily count, keyed by its brand id;
every brand on the application's default mailer shares one (`default`).
Per-brand overrides live on the console's mail-settings page ("Sending
quota"; blank keeps the configured default) and the index shows the
effective pair per brand. The sale dashboard's Messages row shows the
share of each window already used and the mails held per hour;
`KonventixMailQuotaExhausted` fires when a window is full.

A budget's day is a day in the budget's own timezone, and since 2026-09-21
(Round 4, D-MSG-10) that is the brand's mail-settings timezone only for a
brand with its own transport. The shared `default` budget rolls over at
**UTC midnight** whatever timezone the brands on it have set: the provider
counts the one mailbox account's day once, and keying it per brand let two
brands in different zones spend overlapping days against the same 500 —
and let a hold taken by one of them reopen at a midnight the counter
behind it had never reached. Setting a brand's timezone still moves its
quiet hours and its own day; it moves the daily mail budget only once the
brand has its own SMTP transport.

## Support requests

Since Round 4 (#88, #304, ADR-0063) a visitor can say that something is
wrong from any page, and the operator console can too. The report is a
`support_requests` row: what the person typed, one or more of the five
categories (*purchase affected*, *not visible*, *does not work*, *looks
wrong*, *suggestion*), and the technical context they could not be expected
to quote — **both correlation ids**, the route, the release, the brand, the
previous page and the last five visits, the viewport, and the last
JavaScript error the page produced. No IP address is stored anywhere, no
cookies, no form contents of the page being reported, no screenshot; the
dialog lists what goes along before it is sent.

The two correlation ids are the point of the whole feature. `pageCorrelationId`
belongs to the request that misbehaved and is what to paste into the Logs
dashboard; `submitCorrelationId` belongs to the POST that filed the report.
A report therefore arrives already carrying the key to its own log lines
(ADR-0056).

**Where a report lives.** `filed_on_brand_id` records which brand host it
came in on and is *provenance, not a scope*: the console inbox lists across
every brand, and a report filed on the operator console has no brand at all
— it appears under `console` on the dashboards and in the alert
annotations. `support.view-requests` sees the inbox,
`support.triage-requests` moves it, and `support.view-reporter-contact` is
needed to see an unmasked address; every unmasked view is in the audit
trail. `permissions:sync` materializes the **Support Desk** role for every
brand — Support is always on and cannot be switched off per brand
(ADR-0063).

**States.** `received` → `seen` (set automatically the first time somebody
opens it in the console) → `in_progress` → `resolved` or `wont_fix`, with a
reopen from either terminal state back to `in_progress`. Only a terminal
state sets `closed_at`, and `closed_at` is the only retention clock.

**Retention.** `support:purge-requests` runs daily at 03:20 and deletes
closed reports — with their threads — once
`support.retention.days` (`SUPPORT_RETENTION_DAYS`, 180 by default) has
passed since they closed. An **open** report is never purged however old it
is: its age is a triage problem, not a retention one, and
`KonventixSupportUnseen` is what reports it. A window of zero or less is
refused by the sweep rather than obeyed. Compliance's retention-rules UI is
deliberately not wired to this yet; the window is config.

**Privacy.** A subject request reaches Support's own GDPR handler, which
answers for an account **and** for a bare e-mail address — a guest report
is personal data about somebody who never had an account. Erasure nulls the
account id and the address, replaces what the person wrote (in the report
and in their own replies) with `[removed on request]`, and drops the user
agent, the viewport, the visit buffer and the captured client error. The
row itself stays: it is the operator's record that something was broken on
a given release, and what is left says nothing about who reported it.

## Alerts

### KonventixMetricsDown

The scraper got no answer from `/metrics` for five minutes. Either every
web pod is down (KonventixRoleUnavailable fires too) or the secret the
scraper presents no longer matches `HEALTH_ENDPOINT_SECRET`. Check
`kubectl -n konventix get pods`, then the vmagent log for `konventix`:
404 means a wrong or missing secret — `VerifyHealthSecret` answers a
missing route on purpose rather than confirming the endpoint exists to a
caller who cannot open it (ADR-0029) — connection refused or a timeout
means the NetworkPolicy.

### KonventixHealthCheckCritical

One of Database, Redis, Horizon, Schedule or WaitingRoom has reported
failed or crashed for five minutes. Open `admin/health` on the admin host
for the check's message. Database and Redis: the backing service; Horizon:
the horizon deployment; Schedule: the scheduler deployment; WaitingRoom:
the waiting-room supervisor is not draining (KonventixWaitingRoomStuck
says the same from the metric side).

### KonventixHealthCheckFailed

A non-critical check (Cache, DebugMode, UsedDiskSpace) has failed for
fifteen minutes. DebugMode failed means `APP_DEBUG=true` on a
non-local instance: fix the ConfigMap now. UsedDiskSpace: the storage
volume; see which path `HEALTH_DISK_CHECK_PATH` names.

### KonventixQueueJobsFailing

A job exhausted its attempts in the last five minutes. The summary names
the class and queue. The Logs dashboard "Failed jobs by class" panel and
the `queue.job` record with `outcome=failed` carry the exception. Horizon
under `admin/horizon` lets you retry once the cause is fixed.

### KonventixQueueBacklog

The oldest pending job on a queue has waited over a minute for ten
minutes. Either workers are down (see Horizon processes per queue) or a
job is slow. `messaging` backlog during a sale is mail volume and can
wait; `waiting-room` backlog means visitors are not being admitted.

### KonventixWaitingRoomStuck

Tickets are queued but no admission run happened for three minutes. The
`waiting-room` supervisor is not running its tick: check the horizon
deployment and `admin/horizon`; the scheduler must also be up, it
dispatches the batches. Visitors on the holding page see no progress
while this fires.

### KonventixWebhookSilence

Payments are pending and the named provider has sent no webhook for
fifteen minutes. Check the provider's dashboard for webhook delivery
errors, the ingress log for `api/commerce/webhooks/<provider>` with a
non-2xx status, and that the webhook URL and signing secret in the
settlement console are current. `commerce:reconcile-payments --pending`
settles what the provider knows about meanwhile.

If the Orders list shows nothing pending for the brand the metric names,
compare `konventix_payments_pending` on `/metrics` with
`select brand_id, count(*) from orders where status = 'pending' group by 1`.
Before 0.6.2 the gauge only wrote rows for brands with a pending order, and a
row once written stayed in Redis at its last value after the order was
cancelled — dev carried `payments_pending{brand="sxlan"} 1` for two days
against an empty table (2026-09-13). Since 0.6.2 every brand is written on
every scrape, `0` included. A stale row from an older release goes away with
`valkey-cli --scan --pattern 'konventix:metrics:*payments_pending*' | xargs
valkey-cli del` on the app's Redis; the next scrape rewrites it.

### KonventixPaymentFailureRate

More than 30 percent of at least ten payments failed in ten minutes at
one provider. Look at the Sale dashboard "Payments per minute by provider
and outcome" and the provider's dashboard for a declined-reason pattern;
a card-network outage looks exactly like this. Nothing to fix in the
application unless the failures carry an application error.

### KonventixHttp5xxRate

Over five percent of at least twenty requests answered 5xx for five
minutes. The Platform dashboard "5xx per minute by route" names the
route; the Logs dashboard "Latest error records" has the exception with
its trace. A single broken route during a sale: consider disabling the
feature for the brand rather than rolling back.

### KonventixCheckoutSlow

Checkout p95 above three seconds for ten minutes. Compare with the
database (Pulse slow queries under `admin/pulse`), the payment provider's
API latency (webhook p95 on the Sale dashboard is a proxy), and CPU per
role. Scaling web replicas helps only if CPU is the limit.

### KonventixErrorsLogged

The application logged error or worse in the last five minutes. Open the
Logs dashboard "Latest error records". Known noise gets fixed at its
source, not filtered here; this alert is the generic "something is
wrong" and stays sensitive on purpose.

### KonventixScheduleStale

`health:schedule-check-heartbeat` has not run for five minutes: the
scheduler pod is down or wedged. `kubectl -n konventix get pods -l
app.kubernetes.io/component=scheduler`; its log shows `schedule.task`
records when healthy. Without it: no waiting-room ticks, no cart expiry,
no health checks, no backups.

### KonventixResourceHoldSweepStale

`resources:expire-holds` has not finished a run for ten minutes. The
scheduler runs it every minute; it releases *clocked* holds (direct holds
with a `hold_expires_at`) with reason `expired`. Seat bookings a
registrant picked are confirmed in the same transaction as their hold and
are never touched by it (ADR-0060), so nothing a participant sees is lost
while this fires — but an expired direct hold keeps its resource until
someone tries to confirm it, and the place looks taken. If
KonventixScheduleStale fires too, the scheduler is the cause. Otherwise
the command itself is failing: its `schedule.task` records on the Logs
dashboard carry the exception, and
`kubectl -n konventix exec deploy/konventix-scheduler -- php artisan
resources:expire-holds` runs one sweep by hand. The series only exists
once the command has completed at least once on the instance, so an
instance whose brands never switched Resources on never trips this alert;
the sale dashboard's "Seconds since hold sweep" stat shows the same age.

### KonventixDatabaseBackupMissing

No CNPG backup completed in 26 hours. `kubectl -n konventix get backups`
and the CNPG cluster's status show the last attempt and its error; the
object store credentials are the usual cause. WAL archiving may still be
fine (next alert) but point-in-time recovery needs a base backup.

### KonventixWalArchivingFailing

The last WAL archive attempt failed after the last success, for fifteen
minutes. Recovery point objective is degrading by the minute. Check the
CNPG cluster status and the object store; disk on the primary fills once
WAL cannot leave.

### KonventixPodsRestarting

A pod restarted more than three times in thirty minutes. `kubectl -n
konventix describe pod <pod>` shows OOMKilled or a failing probe;
`kubectl logs --previous` shows the last words. Memory limits per role
are in the chart values.

### KonventixClaimInviteMailFailing

More claim-link invitations failed to send than were sent in the last
half hour (#292). The links exist — the buyer was told to copy them — so
nobody is locked out, but a manager whose invitations never arrive stops
trusting the feature. `SendMessage` refused: look for "Claim link mail
could not be sent." in the logs, which carries Messaging's reason — no
resolvable address (the link is bound, so this means a malformed one got
past validation), a brand with no mail identity, or the mail transport
itself, in which case `KonventixQueueJobsFailing` fires beside this.

### KonventixMailQuotaExhausted

A brand's mail sending quota for the hour or the day has been fully used
for five minutes (see "Mail quota" above): the count Messaging keeps of
mail the transport accepted has reached the limit, so every further
non-urgent mail is being held with `hold_reason = mail_quota` and will be
released when the window reopens — the next UTC hour, or the budget's
next midnight (UTC for the shared budget, the brand's own timezone for a
brand with its own transport; see "Mail quota" above). Nothing needs
rescuing: the delivery log
(`/admin/messages`, status *held*) lists what waits and until when, and
the sale dashboard's "Mail held for quota per hour" panel shows the
backlog forming. What to decide is whether the limit is right. For a
brand on the default mailer the budget is the provider's and shared with
every other brand on it; if it fires during a sale, raise
`MAIL_QUOTA_PER_HOUR` / `MAIL_QUOTA_PER_DAY` only once the provider has
raised its own limit, or move the brand onto its own SMTP transport with
its own budget (console, mail settings). For a brand with its own
transport, the override on its mail-settings row is the knob. An hourly
alert that resolves itself within the hour on a publishing day is the
system working; a daily one on a sale day means the confirmations of the
last hours are queued until midnight and is worth a decision before then.

### KonventixConsentGateBlocking

More than five consent prompts were raised for a brand in fifteen minutes
and not one agreement was recorded (ADR-0066, D-CPL-17). Everybody is
being asked and nobody is agreeing, which almost never means everybody
changed their mind. The sale has stopped and nothing else notices: no 5xx,
no failed job, no queue backlog — the logs look healthy, which is exactly
why this rule exists.

**The checkout has had two steps since ADR-0066 (D-CPL-07)**, which is
worth knowing before reading any of this. `/pay/registration/{id}`,
`/pay/order/{id}` and `/cart` are step one: the order, the deadline and the
settlement accounts, and **no legal text at all**. The texts and the one
accept control live on `…/confirm`, resolved for the provider chosen in step
one, and a brand with a single offered account is redirected past step one
on both pay pages. So a report of "the checkout shows no terms" is the
expected state of step one, and the page to look at is the confirmation.
Going back to step one clears the recorded provider and the acceptance with
it, by design — a payer may not be held to a list they never saw.

Three causes, in the order they are worth checking. **An unpublished or
un-effective text**: the interstitial and the checkout slot render nothing
to agree to when the policy a gate asks for has no published version whose
`effective_at` has arrived, so the control is empty and the POST has
nothing to record. `/admin/policies` for the brand, and the settlement
console's gap notice (#222), say which slug is missing. **A redirect
loop**: a gate that sends somebody to `policies.required` where the same
gate applies again — `require-contract-consent` is applied by each owning
module to its own routes and must never reach `policies.*`, the login
routes, `/my/*` or Support's report channel (T-9, ADR-0063). Watch one
request in the access log (`http.request` records) and see whether the
redirect repeats. **A broken control**: check the browser console on the
page the dashboard's "Prompts per hour by surface" names as the surface —
a page that never renders the checkbox cannot be agreed to.

The panel beside it is "Grants against prompts per hour". The refusal rate
is derived from those two series and is not a counter: the interstitial's
"Not now" increments nothing, on purpose, so a high gap is this alert's
evidence and not a separate number to chase.

### KonventixEntryConditionRefusalsAtDoor

More than three entry-condition declarations were refused at the entrance
in ten minutes for one brand (ADR-0066, D-ADM-06). Two readings, and the
door staff can usually tell them apart in a minute: a reduced-fare type is
being abused — people bought a student ticket without a student ID — or
the door is misreading the hint and refusing people who did bring what was
asked for.

Look at the registrations index filtered on entry-condition state for that
brand: it lists which condition is being refused and what the ticket told
the holder to bring (`document_hint`, printed on the ticket PDF). If the
hint and what the door is asking for disagree, fix the hint on
`/admin/entry-conditions` — it reaches tickets rendered from that point on
and the admissions issued afterwards; admissions already issued keep the
list they were stamped with, so tell the door directly as well.

Nothing needs rescuing at the turnstile either way. A refusal records a
fact: it never revokes the admission and never reaches Commerce (T-8), so
nobody is turned away by the system and no money moves. Whether a refused
person is let in is the operator's decision at the door, and an operator
who wants a *blocking* check sets `approval: required` on the registration
type instead, which is decided before the event rather than at the
entrance.

### KonventixSupportPurchaseAffected

Somebody has just reported a problem in the **purchase affected** category
(D-SUP-06). This is not a rate and not a threshold: it fires on the first
one in ten minutes, because the category means a person is standing in
front of a checkout that is not working for them right now, and nothing
else on this dashboard can tell you that. A quiet day with one of these is
the system working as intended.

`{{ $labels.brand }}` names the brand host the report came in on, or
`console` for a report filed on the operator console.

What to do, in order. **Open the console inbox**
(`/admin/support-requests`, filtered on the category) and read the newest
report: the reference is its last eight characters, upper-cased, and it is
the word the reporter has as well. **Paste the page correlation id into the
Logs dashboard** — the report carries the id of the request that
misbehaved, not only of its own submission, so the log lines behind the
problem are one query away and need no guessing about timestamps. **Check
whether it is already known**: the platform dashboard's Support row, the
payment panels of the sale dashboard and `KonventixPaymentFailureRate` say
whether this is one person's browser or everybody's checkout. If it is
everybody's, mark the report as a known issue with a notice — the dialog
then shows that notice to the next person about to report the same thing,
which is both kinder and quieter.

If several of these arrive within minutes of each other, treat it as a
payment incident and not as support volume: the reports are the symptom,
and `KonventixWebhookSilence` / `KonventixPaymentFailureRate` are where the
cause usually shows.

### KonventixSupportUnseen

A support report has been sitting in `received` — nobody has opened it in
the console — for more than an hour. The gauge is the age of the *oldest*
unopened report and reads 0 when there is none, so the alert resolves the
moment somebody opens the oldest one.

This is a staffing signal, not a fault: nothing is broken, somebody is
simply not reading the inbox. On a sale day or during the event that is
worth fixing within minutes, because the reports arriving then are the ones
that say the door or the checkout is broken. Off-season an hour is
harmless and the alert can be acknowledged.

Opening a report sets `seen` automatically, which is what ends the clock —
there is no separate "acknowledge" control to forget. If the inbox looks
empty while this is firing, check the brand filter: the gauge counts every
brand *and* the console-filed reports, and the console ones appear under no
brand at all.

### KonventixRoleUnavailable

A deployment has had unavailable replicas for ten minutes. `kubectl -n
konventix get deploy` and `describe` on the one named; an image pull
error, a crash loop, or a node without room. Web unavailable means
visitors see errors; pulse unavailable means only the dashboard is stale.
