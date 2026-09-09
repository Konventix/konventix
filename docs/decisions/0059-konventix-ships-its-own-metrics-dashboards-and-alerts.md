# ADR-0059: Konventix ships its own metrics, dashboards and alerts

**Status:** accepted

## Context

Until now nothing in the application was scrapeable: Pulse writes to
Postgres for humans, Horizon is a UI, Spatie Health stores verdicts that
nobody is paged about, and the Prometheus and Grafana under contrib/ are a
k6 sink. The platform that runs Konventix already operates VictoriaMetrics
with the operator, vmalert and Alertmanager, VictoriaLogs collecting every
container's stderr, and Grafana through grafana-operator (platform
ADR-0031, ADR-0032). Its rules: native VM CRDs, no prometheus-operator
converter; dashboards as `GrafanaDashboard` CRs selecting `dashboards:
grafana`; alerting by e-mail; `severity=info` blackholed. GitLab #173, #111,
#96 and #168 ask for metrics, a dashboard, alerts and a recipient before the
sale, and the design at docs/superpowers/specs/2026-09-09-observability-design.md
was approved on 2026-09-09.

## Decision

1. **One registry, one target.** Counters and histograms are written at
   event time by whichever role saw the event, into promphp's RedisNg
   storage on the application's Redis connection; gauges are computed at
   scrape time by closures. Any web pod renders the whole registry at
   `/metrics`; the chart scrapes the web Service once per interval as a
   single static target, so replicas never duplicate a series. Per-pod
   resource numbers come from cAdvisor.
2. **spatie/laravel-prometheus for the endpoint and gauges,
   `Konventix\Platform\Observability\Metrics` for counters and histograms.** The package's
   counters are per-process and re-increment on every render; they are not
   used. The catalogue (`MetricCatalog`) is the list of what the application
   emits, and `Metrics` refuses a name that is not on it.
3. **The endpoint reuses `HEALTH_ENDPOINT_SECRET`**, as the health header or
   as a bearer token, and answers 404 without it. One diagnostics secret.
4. **Ownership follows ADR-0003.** The host declares HTTP, queue, health,
   schedule and log-record metrics; a module declares its own entries and
   records them with listeners on its own events, registered in its own
   provider. No amounts per person, no user or ticket ids as labels.
5. **Dashboards and rules are source in this repository**, under
   `contrib/observability/`: dashboards generated from JS sources into
   committed JSON, rules as Prometheus rule YAML with `__RELEASE__` and
   `__NAMESPACE__` tokens and vmalert-tool unit tests. The Helm chart
   vendors a copy and renders the CRs; a chart CI job refuses a copy that
   differs from the app tag it pins.
6. **Every alert carries `team: konventix` and a severity of `warning` or
   `critical`**, a summary and a runbook anchor into docs/operations.md.
7. **Logs stay JSON on stderr, now with more on every record**: release,
   route or job or command, brand slug and user ULID via `Context`, stack
   traces on exceptions, one summary record per request (`http.request`),
   job (`queue.job`) and failed scheduled task (`schedule.task`). The
   application's own `schedule:work` runs `schedule:run --quiet` so console
   lines stop gluing themselves to JSON records.
8. **A feature with operational state adds a metric, a panel and, where it
   can fail silently, an alert.** `ObservabilityCoverageTest` enforces the
   mechanical half: declared metrics appear on a dashboard, referenced
   metrics are declared, alerts are deliverable.
9. **Not tracing.** The correlation id (ADR-0056) is the join key across
   ingress line, request record and job records. OpenTelemetry to a trace
   backend stays #122; the platform runs none.
10. **The metric primitives live in the Platform module.** `MetricKind`,
    `MetricDefinition`, `MetricCatalog`, `Metrics` and `UnknownMetric` are
    `Konventix\Platform\Observability\*`, because `ArchitectureTest` forbids
    any `App\` import inside `app-modules/` (ADR-0003); the host keeps
    `CorrelationId`, `RequestContext`, the registry binding and the endpoint.
11. **`Metrics` validates loudly and reports quietly.** `increment()` and
    `observe()` throw on a name or label the catalogue does not recognise —
    a programmer error — but catch and report a storage failure rather than
    letting it propagate, so a Redis hiccup can never abort the order,
    payment or admission being counted. Recorders stay plain: they call
    `Metrics` and nothing else, with no try/catch of their own.
12. **The registry runs on its own Redis connection.** `config/database.php`
    defines a `metrics` connection with no phpredis key prefix, because
    promphp's RedisNg storage cannot see through `OPT_PREFIX`; the registry
    applies its own prefix instead, via `prometheus.redis_prefix`
    (`konventix:metrics:`). `MetricsRedisStorageTest` exercises that path
    against real Redis under its own test prefix.
13. **Brand slugs for labels and log context come from
    `Konventix\Platform\Brands\BrandSlugs::of()`**, cached for five minutes,
    so a brand rename cannot go stale for the lifetime of a long-running
    worker.
14. **A job's queue name is read by reflection.**
    `ObservabilityServiceProvider::jobQueueName()` inspects the job's queue
    property directly, because the sync driver's `getQueue()` always answers
    `sync`. The failed-jobs counter labels the class `job_class`, never
    `job` — the scrape reserves `job` for the release name.

## Consequences

- Redis holds a few thousand small keys under `konventix:metrics:`; a
  flush loses counters since the last scrape only.
- The HTTP histogram carries a route label only for the allowlist in
  `config/prometheus.php`; the long tail has its exact durations in the
  `http.request` records instead.
- Dashboards edited in Grafana are drift and get reverted by the operator;
  change the source and rebuild.
- Reverb, SSR and the ingress edge are unmeasured by the application; the
  edge is a platform follow-up (ingress-nginx metrics).
- The user ULID is in every log record of a signed-in request. VictoriaLogs
  keeps 30 days.
