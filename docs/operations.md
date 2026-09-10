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

The other eleven — KonventixMetricsDown, KonventixHealthCheckFailed,
KonventixQueueJobsFailing, KonventixWaitingRoomStuck, KonventixWebhookSilence,
KonventixPaymentFailureRate, KonventixHttp5xxRate, KonventixCheckoutSlow,
KonventixWalArchivingFailing, KonventixPodsRestarting and
KonventixResourceHoldSweepStale — are not triggered
by hand for #168; each has a `vmalert-tool` unit test in
`contrib/observability/alerts/tests/konventix.rules.test.yaml` that shapes an
input series so the rule's expression fires and asserts the labels vmalert
reports, which is the acceptance in place of a live trigger. Two of those eleven
need more than the unit test to prove the *metric*, not just the rule, is
honest: KonventixWebhookSilence and KonventixPaymentFailureRate are proven by
running a sandbox payment flow — a Stripe test-mode webhook withheld, and a
run of declined test cards. KonventixWalArchivingFailing and
KonventixPodsRestarting are platform-side, sourced from CNPG's and kubelet's
own exporters rather than anything this application emits, so they are
observed when they occur in the cluster rather than provoked.

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

### KonventixRoleUnavailable

A deployment has had unavailable replicas for ten minutes. `kubectl -n
konventix get deploy` and `describe` on the one named; an image pull
error, a crash loop, or a node without room. Web unavailable means
visitors see errors; pulse unavailable means only the dashboard is stale.
