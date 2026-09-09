# Observability: metrics, dashboards, alerts and logs

Date: 2026-09-09. Status: approved design, awaiting spec review.
Issues: #173 (metrics export, base), #111 (Grafana dashboard), #96 (alerting),
#168 (pre-sale alerts), #157 (cutover logging end to end), #122 (tracing, stays open).

## 1. Goal

Konventix ships its own observability. The application exposes metrics, writes
structured logs that VictoriaLogs parses at ingest, and the Helm chart delivers
ready-to-use Grafana dashboards and alert rules into the platform's existing
VictoriaMetrics, VictoriaLogs, vmalert, Alertmanager and grafana-operator stack.
Future agents extend all three (metric, panel, alert) when they add a feature with
operational state, and a test fails the build when they forget.

## 2. Platform facts the design rests on

Verified 2026-09-09 in `~/git/sxcs/rancher/fleet-workloads` and on the live cluster
(context `rancher`, kubeconfig `~/.kube/sxcs.yaml`).

- VictoriaMetrics k8s-stack 0.91.2 with the operator (`fullnameOverride: vm`, namespace
  `victoria-metrics`). Both `VMAgent` and `VMAlert` run with `selectAllByDefault: true`,
  so scrape and rule objects in namespace `konventix` are picked up without labels.
  ADR-0031 of the platform: native VM CRDs only, no prometheus-operator converter.
- Grafana via grafana-operator 5.25.0. Dashboards are `GrafanaDashboard` CRs selecting
  the instance with `instanceSelector.matchLabels.dashboards: grafana` and
  `allowCrossNamespaceImport: true`. Datasource uids: `VictoriaMetrics` (type
  prometheus), `VictoriaLogs` (type victoriametrics-logs-datasource). UI edits are drift
  (platform ADR-0032). Grafana-native alerting is unused.
- vmalert plus Alertmanager, email only, one recipient, configured in the platform
  repo. Route policy: `severity="info"` is blackholed, so deliverable alerts are
  `warning` or `critical`.
- VictoriaLogs (two independent VLSingle, 30 days) collects every container's log via
  VLAgent. JSON lines are parsed into fields at ingest (verified on horizon and web
  records: `extra.correlation_id`, `context.exception.class`, `level_name`).
  Stream fields: `kubernetes.pod_namespace`, `kubernetes.pod_name`,
  `kubernetes.container_name`.
- ingress-nginx (class `nginx-public`) writes its `$req_id` as the last field of each
  access line and forwards it as `X-Request-ID`; the app adopts it as the correlation
  id (ADR-0056). Verified: web records carry 32-hex ids.
- Scraped already: CNPG (`cnpg_collector_last_available_backup_timestamp`,
  `cnpg_pg_stat_archiver_*`), kube-state (`kube_deployment_status_replicas_unavailable`,
  `kube_pod_container_status_restarts_total`), cAdvisor. Not scraped: the app, Valkey,
  both ingress controllers, cert-manager. No `kube_job_*` series exist.
- Konventix runs as a Fleet `HelmOp` (`apps/konventix-app/helmop.yaml`, values inline)
  consuming the chart from `registry.sxcs.de`, namespace `konventix`, roles web,
  horizon, scheduler, pulse, reverb, ssr.

## 3. Architecture

```
web / horizon / scheduler / pulse processes
   │  counters + histograms (event time)      gauges (scrape time, closures)
   ▼                                          ▼
promphp CollectorRegistry ── Redis storage (existing Laravel redis connection)
   ▲
   │ GET /metrics  (Authorization: Bearer <HEALTH_ENDPOINT_SECRET>, or X-Health-Secret)
VMScrapeConfig (static target: web Service, one scrape per interval, job="<release>")
   │
VictoriaMetrics ──► vmalert (VMRule) ──► Alertmanager (email)
                └─► Grafana (GrafanaDashboard CRs, folder "Konventix")
VictoriaLogs   ──► Grafana (Konventix Logs dashboard)
```

One shared registry, one scrape target. All roles increment the same Redis-backed
counters; any web pod renders the whole registry. A single static target avoids
duplicated series across replicas. Per-pod resource metrics come from cAdvisor.

## 4. Application: metrics

### 4.1 Package and wiring

- New dependency `spatie/laravel-prometheus` (^1.7, Laravel 13, PHP 8.4+). It brings the
  endpoint, a fluent gauge API and 13 Horizon and queue collectors, and depends on
  `promphp/prometheus_client_php`.
- `App\Providers\ObservabilityServiceProvider` rebinds `Prometheus\CollectorRegistry`
  to a registry over `Prometheus\Storage\Redis::fromExistingConnection()` using the
  Laravel `redis` connection's phpredis client (so Valkey ACL credentials come from
  the existing config), with key prefix `konventix:metrics:`. In tests and when
  `prometheus.storage` is `memory`, the in-memory adapter is used.
- `config/prometheus.php` (published): `urls.default = 'metrics'`,
  `default_namespace = 'konventix'`, `middleware = [VerifyHealthSecret::class]`,
  plus project keys `storage` (`redis` or `memory`, env `PROMETHEUS_STORAGE`) and
  `http.routes` (the duration-histogram allowlist, section 4.3).
- `VerifyHealthSecret` additionally accepts `Authorization: Bearer <secret>`, because
  vmagent sends credentials as a bearer token. Behaviour otherwise unchanged (404).
- `App\Observability\Metrics`: thin wrapper over the registry for counters and
  histograms with atomic increments from any process. Signature style:
  `Metrics::counter('orders_total')->inc(['brand' => $slug, 'outcome' => 'paid'])`,
  `Metrics::histogram('payment_webhook_duration_seconds')->observe($seconds, [...])`.
- `App\Observability\MetricCatalog`: the declared list of every metric the app emits
  (name, type, help, label names), including the names of the Spatie collectors in
  use. `Metrics` registers from it; the invariant test (section 10) reads it.
- Rule for agents: gauges via `Prometheus::addGauge()` closures, counters and
  histograms via `Metrics`. Never `Prometheus::addCounter()` (its counters are
  per-process and re-increment on every render).

### 4.2 Ownership

Cross-cutting mechanism lives in `app/Observability/` and the host provider
(ADR-0003: deployment tooling is composed by the host). Domain recorders live in the
owning module as listeners on that module's own events, registered by the module's
provider. Precedent: `Konventix\Commerce\Health\WaitingRoomCheck`.

### 4.3 Metric catalogue, v1

All names carry the `konventix_` namespace. No amounts per person, no user or ticket
ids as labels. Brand label is the brand slug.

Commerce (module `commerce`, listeners in `src/Listeners/Metrics/`):

| Metric | Type | Labels | Source |
| --- | --- | --- | --- |
| `orders_total` | counter | brand, outcome (placed, paid, cancelled) | OrderPlaced, OrderPaid, OrderCancelled |
| `order_paid_amount_cents_total` | counter | brand, currency | OrderPaid |
| `payments_total` | counter | brand, provider, outcome (received, failed, reversed) | PaymentReceived, PaymentFailed, PaymentReversalRecorded |
| `payment_webhooks_total` | counter | provider, outcome (accepted, rejected, error) | webhook controllers (Stripe, Mollie, Unzer) |
| `payment_webhook_duration_seconds` | histogram | provider | webhook controllers |
| `payment_webhook_last_received_timestamp_seconds` | gauge (set at event) | provider | webhook controllers; when no value exists in the registry yet, `Metrics` seeds it with the current time once per configured provider (set-if-absent, never overwritten on a later pod boot) so "silence" alerts do not fire on a fresh deploy |
| `payments_pending` | gauge (scrape) | brand | orders awaiting payment |
| `waiting_room_depth` | gauge (scrape) | brand | WaitingRoomStore |
| `waiting_room_admissions_total` | counter | brand | AdmitWaitingRoomBatch (next to the existing Pulse record) |
| `waiting_room_last_admission_timestamp_seconds` | gauge (scrape) | brand | WaitingRoomStore::lastAdmissionAt |
| `carts_expired_total` | counter | brand | CartExpired |
| `stock_exhausted_total` | counter | brand | StockExhausted |

Messaging (module `messaging`): `messages_total` counter by channel and outcome
(delivered, failed, suppressed) from MessageDelivered, MessageFailed, MessageSuppressed.

Platform (host, `app/Observability/`):

| Metric | Type | Labels | Source |
| --- | --- | --- | --- |
| `http_requests_total` | counter | method, status, route | global middleware `RecordHttpMetrics`; route = route name or `unnamed`; skips `metrics`, `health-json`, `/up` |
| `http_request_duration_seconds` | histogram | route | same middleware; route restricted to `prometheus.http.routes`, else `other` |
| `queue_jobs_processed_total` | counter | queue | JobProcessed |
| `queue_jobs_failed_total` | counter | queue, job | JobFailed |
| `horizon_*`, `queue_*` | gauges | queue, connection | Spatie Horizon and queue collectors for queues default, waiting-room, messaging |
| `health_check_status` | gauge (scrape) | check | latest stored Spatie Health result: 0 ok, 1 warning, 2 failed, 3 crashed, 4 skipped |
| `schedule_last_heartbeat_timestamp_seconds` | gauge (scrape) | | the schedule heartbeat Spatie Health writes |
| `log_records_total` | counter | level | MessageLogged listener, levels warning and above (warning, error, critical, alert, emergency) |
| `build_info` | gauge | version | `config('app.version')` |

Histogram buckets: HTTP 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10 seconds; webhooks the same.

Route allowlist default: `home`, `event.index`, `event.show`, `commerce.shop.index`,
`commerce.shop.show`, `commerce.cart.show`, `commerce.cart.checkout`,
`commerce.pay.order.start`, `commerce.pay.order.show`, `commerce.waiting-room.show`,
`commerce.waiting-room.status`, `login`, `registration.store`, and every route whose
name starts with `api.commerce.webhooks.`.

Not in v1: Reverb connection metrics (no hook exists), SSR, tracing (#122).

## 5. Application: logging

- Stack traces: the stderr channel sets `formatter_with => ['includeStacktraces' => true]`.
- Context on every record (`extra.*`). `AssignCorrelationId` stays first and
  unchanged. A new `App\Observability\RequestContext` listener set adds the rest at
  the moment each value becomes known: `RouteMatched` sets `route` (route name) and
  `brand` (slug via `BrandContext`), `Authenticated` sets `user_id`, the existing
  JobProcessing and CommandStarting listeners set `job` (class) or `command` (name)
  and re-apply the brand the queue propagation restored. `release`
  (`config('app.version')`) is set once per process. `user_id` is the authenticated
  user's ULID, pseudonymous, retention 30 days in VictoriaLogs (section 12).
- Summary records, one per unit of work, via framework events:
  - `RequestHandled`: level info, message `http.request`, fields method, route,
    status, duration_ms. Skips `metrics`, `health-json`, `/up`.
  - `JobProcessed` and `JobFailed`: level info (failed: error), message `queue.job`,
    fields job, queue, outcome, duration_ms, attempts.
  - `ScheduledTaskFinished`: level debug; `ScheduledTaskFailed`: level error; message
    `schedule.task`, fields command, exit_code, duration_ms.
- Scheduler noise: `schedule:work` captures the child's stdout and stderr into one
  stream, which glues the "Running [...]" console line to the JSON error record and
  makes those records unparseable (5,232 such lines per day on dev). The app registers
  `App\Console\Commands\ScheduleWorkQuietly` under the same name `schedule:work`,
  appending `--quiet` to the `schedule:run` invocation. Chart command unchanged. If
  the framework does not let an application command shadow `schedule:work`, the
  command is named `konventix:schedule-work` and the chart's scheduler role runs it
  instead.
- The `log_records_total` counter (section 4.3) makes error records alertable
  without a log-based vmalert.

## 6. Dashboards and rules: source of truth in the app repo

Directory `contrib/observability/`:

```
contrib/observability/
  README.md                       what lives here, tokens, how to test locally
  dashboards/
    konventix-sale.json
    konventix-platform.json
    konventix-logs.json
  alerts/
    konventix.rules.yaml          VMRule-shaped groups (Prometheus rule format)
    tests/konventix.rules.test.yaml   vmalert-tool unit tests
  render.sh                       substitutes tokens for local runs and tests
```

Tokens in rule files: `__RELEASE__` (Helm release name, default `konventix`) and
`__NAMESPACE__` (default `konventix`). Dashboards need no tokens: they use Grafana
variables `namespace` and `release` with those defaults and pin datasource uids
`VictoriaMetrics` and `VictoriaLogs`.

Dashboard uids: `konventix-sale`, `konventix-platform`, `konventix-logs`.

### 6.1 Konventix Sale

Stat row: orders placed, paid, cancelled (range totals), paid amount by currency,
payment failure rate, waiting room depth, admissions per minute. Time series: orders
by outcome per brand, payments by provider and outcome, webhooks per provider with
p95 duration, seconds since last webhook per provider, pending payments, waiting
room depth and admissions, carts expired and stock exhausted.

### 6.2 Konventix Platform

HTTP: request rate by status class, 5xx ratio, p50/p95/p99 by allowlisted route.
Queues: workload per queue, oldest pending job age, jobs processed and failed per
queue, Horizon status and processes. Health: `health_check_status` per check as a
state timeline, schedule heartbeat age. Runtime: CPU and memory per role from
cAdvisor, restarts, unavailable replicas. Database: CNPG backup age, WAL archiving
status, replication lag for `<release>-db`. Build info as a stat.

### 6.3 Konventix Logs

Errors by exception class over time, error and warning records per container,
`http.request` records by route and status, duration percentiles from summary
records (LogsQL `quantile`), and a correlation-id panel: variable `correlation_id`
shows the ingress access line (extracted at query time from the nginx line), the
`http.request` record, every record of that request and its jobs.

### 6.4 Alert rules

Group `konventix.app`, all with labels `team: konventix` and `severity`, annotations
`summary` and `runbook` (a section anchor in `docs/operations.md`, section 9).

| Alert | Expression sketch | For | Severity |
| --- | --- | --- | --- |
| KonventixMetricsDown | `up{job="__RELEASE__"} == 0` | 5m | critical |
| KonventixHealthCheckCritical | `konventix_health_check_status{check=~"Database\|Redis\|Horizon\|Schedule\|WaitingRoom"} >= 2` | 5m | critical |
| KonventixHealthCheckFailed | other checks `>= 2` | 15m | warning |
| KonventixQueueJobsFailing | `increase(konventix_queue_jobs_failed_total[5m]) > 0` | 0m | warning |
| KonventixQueueBacklog | `konventix_queue_oldest_pending_job_age > 60` | 10m | warning |
| KonventixWaitingRoomStuck | `konventix_waiting_room_depth > 0 and time() - konventix_waiting_room_last_admission_timestamp_seconds > 180` | 3m | critical |
| KonventixWebhookSilence | `konventix_payments_pending > 0 and time() - konventix_payment_webhook_last_received_timestamp_seconds > 900` per provider | 15m | critical |
| KonventixPaymentFailureRate | failed / (failed + received) over 10m `> 0.3` with at least 10 payments | 10m | warning |
| KonventixHttp5xxRate | 5xx / all over 5m `> 0.05` with at least 20 requests | 5m | warning |
| KonventixCheckoutSlow | p95 of `http_request_duration_seconds{route="commerce.cart.checkout"}` over 10m `> 3` | 10m | warning |
| KonventixErrorsLogged | `increase(konventix_log_records_total{level=~"error\|critical\|alert\|emergency"}[5m]) > 0` | 0m | warning |
| KonventixScheduleStale | `time() - konventix_schedule_last_heartbeat_timestamp_seconds > 300` | 5m | critical |

Group `konventix.platform` (series from CNPG, kube-state, cAdvisor):

| Alert | Expression sketch | For | Severity |
| --- | --- | --- | --- |
| KonventixDatabaseBackupMissing | `time() - max(cnpg_collector_last_available_backup_timestamp{namespace="__NAMESPACE__", pod=~"__RELEASE__-db-.*"}) > 26*3600` | 30m | critical |
| KonventixWalArchivingFailing | `cnpg_pg_stat_archiver_last_failed_time > cnpg_pg_stat_archiver_last_archived_time` same selector | 15m | warning |
| KonventixPodsRestarting | `increase(kube_pod_container_status_restarts_total{namespace="__NAMESPACE__", pod=~"__RELEASE__-.*"}[30m]) > 3` | 0m | warning |
| KonventixRoleUnavailable | `kube_deployment_status_replicas_unavailable{namespace="__NAMESPACE__", deployment=~"__RELEASE__-.*"} > 0` | 10m | critical |

On dev today the backup timestamp is 0, so KonventixDatabaseBackupMissing fires at
once there. That is a true finding (#167), not a rule defect.

## 7. Local development rig

`contrib/prometheus.yml` gains a scrape job for `laravel.test` at `/metrics` with a
bearer token; `.env.example` sets `HEALTH_ENDPOINT_SECRET` to a fixed development
value and `prometheus.yml` carries the same literal with a comment naming the env key; `contrib/grafana/provisioning/datasources`
gains a second datasource with uid `VictoriaMetrics` pointing at the same local
Prometheus, so the Sale and Platform dashboards render on sail unchanged. The Logs
dashboard has no local backend and says so in its description. The existing k6 rig is
untouched. `render.sh` plus `vmalert-tool unittest` in a container run the rule tests
locally.

## 8. Helm chart (`~/git/konventix/konventix-helm`)

- `charts/konventix/files/observability/` holds a copy of `dashboards/` and
  `alerts/konventix.rules.yaml` from the app repo. `contrib/sync-observability.sh
  <app-checkout>` copies them. A chart CI job fetches the same files from the app
  repo at tag `v<appVersion>` and fails on a diff, so the copy cannot drift past a
  release. If the cross-project token is not permitted, the job compares against a
  committed checksum manifest instead and the sync script updates it.
- Templates under `templates/monitoring/`, all gated by `monitoring.enabled`:
  `vmscrapeconfig.yaml` (static target `<fullname>-web.<namespace>.svc:8000`, the chart's web Service, path `/metrics`,
  bearer token from the app secret's `HEALTH_ENDPOINT_SECRET` key, relabel
  `job=<release>`, interval from values), `vmrule.yaml` (rule file with tokens
  replaced by release and namespace), `grafanafolder.yaml` and one
  `GrafanaDashboard` per JSON file (instanceSelector and folder from values,
  `allowCrossNamespaceImport: true`).
- Values: `monitoring.enabled` (false), `monitoring.scrape.interval` (30s),
  `monitoring.scrape.secretName` (defaults to the app secret),
  `monitoring.rules.enabled`, `monitoring.rules.labels` (`team: konventix`),
  `monitoring.dashboards.enabled`, `monitoring.dashboards.instanceSelector`
  (`dashboards: grafana`), `monitoring.dashboards.folder` (`Konventix`). All added to
  `values.schema.json`. `ci/values-full.yaml` enables monitoring so lint and
  kubeconform cover the CRs; the CRD catalog must contain the four kinds, otherwise
  the job passes `-ignore-missing-schemas` for those kinds only.
- Chart version bump, lockstep with the app release.

## 9. Fleet repo and docs

- MR to `sxcs/rancher/fleet-workloads`: `monitoring: {enabled: true}` in the konventix
  HelmOp values, after the chart release. Alerts route to the existing email receiver.
- `docs/operations.md` (new, app repo): alert list with what each means and what to
  do, dashboards, how to find a purchase by correlation id, how to trigger each alert
  once (#168 acceptance).
- Platform follow-ups, filed as issues, not done here: ingress-nginx metrics and JSON
  access log format, Valkey exporter and scrape (platform ADR-0033), cert-manager
  scrape and expiry rule, a `team=konventix` Alertmanager route once a recipient is
  named.

## 10. Guidance for future agents

- ADR-0059 "Konventix ships its own metrics, dashboards and alerts": the decisions in
  sections 3 to 6, the token convention, the Spatie versus Metrics rule, the single
  scrape target.
- `.ai/rules/observability.md`, indexed for `app/Observability/**`,
  `contrib/observability/**`, `config/prometheus.php`, `config/logging.php`,
  `config/health.php`, `app-modules/*/src/Listeners/Metrics/**`: how to add a metric
  (catalogue entry, recorder, test), which dashboard gets the panel, when an alert is
  required (a state that can fail silently), the severity rule, the token rule, how
  to run the rule tests.
- `CLAUDE_extra.md` definition of done gains: a feature with operational state adds a
  metric, a dashboard panel and, where it can fail silently, an alert.
- `tests/Feature/ObservabilityCoverageTest.php` enforces it: every `konventix_*` name in
  a dashboard or rule is in the catalogue; every catalogue entry appears in at least
  one dashboard; every rule carries `team` and a severity of warning or critical;
  dashboards use only the two datasource uids; the rendered `/metrics` document
  declares no name outside the catalogue.

## 11. Testing

- `MetricsEndpointTest`: 404 without secret, 200 with header or bearer, output contains
  a Spatie gauge and a `Metrics` counter in one document, `/metrics` itself is absent
  from `http_requests_total`.
- Per-recorder tests in the owning module: fire the event, render, assert the sample.
- `HttpMetricsTest`: allowlisted route gets its label, others get `other`, status and
  method labels.
- `StructuredLogsTest` (extends the CorrelationIdTest approach with a Monolog
  TestHandler): context fields on request, job and command records; summary records
  with their fields; stack trace present on an exception record; scheduler command
  passes `--quiet`.
- `ObservabilityCoverageTest` as in section 10.
- CI: `vmalert-tool unittest` on the rendered rules; chart lint, kubeconform, sync
  check.
- After rollout on dev: `up{job="konventix"} == 1`, all three dashboards imported
  (`kubectl get grafanadashboard -n konventix`), `VMRule` status operational, one alert
  triggered on purpose (stop Horizon) and received by email.

## 12. Decisions and flagged items

- Source of truth for dashboards and rules is the app repo; the chart vendors a copy
  (user decision 2026-09-09).
- Package: spatie/laravel-prometheus for endpoint and gauges, promphp directly for
  counters and histograms (user approved).
- HTTP duration histogram carries a route label only for the allowlist (user approved).
- `user_id` in log context: approved. Pseudonymous ULID, no names or emails.
- Alerts go to the platform's existing email receiver.
- Tracing stays in #122. Correlation id is the join key; OTLP to a trace backend would
  be the next step and needs a backend the platform does not run.
- The metrics endpoint reuses `HEALTH_ENDPOINT_SECRET`. One diagnostics secret, no new
  OpenBao entry.

## 13. Amendment 2026-09-09 (during implementation)

`tests/Unit/ArchitectureTest` forbids any `App\` import inside `app-modules/`
(ADR-0003: modules know nothing about the host). The metric primitives
`MetricKind`, `MetricDefinition`, `MetricCatalog`, `Metrics` and `UnknownMetric`
therefore live in the Platform module as `Konventix\Platform\Observability\*`
(`app-modules/platform/src/Observability/`), not in `app/Observability/`. The
host still binds the registry, composes the endpoint and keeps `CorrelationId`
and `RequestContext`. Sections 4.1, 4.2 and 10 read with that substitution.

## 14. Out of scope

Reverb metrics, SSR metrics, Pulse retention tuning, Plausible (#90), external
monitoring (#148, #157 are operator tasks; this work supplies what #157 verifies),
Grafana-native alerting, log-based vmalert rules.
