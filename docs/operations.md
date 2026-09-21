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

The other twelve — KonventixMetricsDown, KonventixHealthCheckFailed,
KonventixQueueJobsFailing, KonventixWaitingRoomStuck, KonventixWebhookSilence,
KonventixPaymentFailureRate, KonventixHttp5xxRate, KonventixCheckoutSlow,
KonventixWalArchivingFailing, KonventixPodsRestarting,
KonventixResourceHoldSweepStale and KonventixClaimInviteMailFailing — are not triggered
by hand for #168; each has a `vmalert-tool` unit test in
`contrib/observability/alerts/tests/konventix.rules.test.yaml` that shapes an
input series so the rule's expression fires and asserts the labels vmalert
reports, which is the acceptance in place of a live trigger. Two of those twelve
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

### KonventixRoleUnavailable

A deployment has had unavailable replicas for ten minutes. `kubectl -n
konventix get deploy` and `describe` on the one named; an image pull
error, a crash loop, or a node without room. Web unavailable means
visitors see errors; pulse unavailable means only the dashboard is stale.
