---
paths:
  - 'app/Observability/**'
  - 'app-modules/platform/src/Observability/**'
  - 'app/Providers/ObservabilityServiceProvider.php'
  - 'app-modules/*/src/Metrics/**'
  - 'app-modules/*/src/Listeners/Metrics/**'
  - 'contrib/observability/**'
  - 'config/prometheus.php'
  - 'config/logging.php'
  - 'config/health.php'
  - 'docs/operations.md'
---

# Observability

## A feature with operational state adds a metric, a panel and, where it can fail silently, an alert
ADR-0059. "Operational state" is anything an operator would ask about on the sale day: a queue, an external call, a count that should move, a heartbeat. Add the catalogue entry in the owning module's `src/Metrics/<Module>Metrics.php` (host: `ObservabilityServiceProvider::registerHostMetrics`), record it with a listener in `src/Listeners/Metrics/` on the module's own event, put a panel in `contrib/observability/src/{sale,platform,logs}.mjs` and run `npm run observability:build`, and add a rule to `contrib/observability/alerts/konventix.rules.yaml` with a unit test in `alerts/tests/`. `ObservabilityCoverageTest` fails when a declared metric is on no dashboard or a dashboard names an undeclared one.

## Gauges through the Spatie facade, counters and histograms through Metrics, never Prometheus::addCounter
The package's counters live in one process and re-increment on every render; `Konventix\Platform\Observability\Metrics` writes atomically into the shared Redis registry from any role. A gauge is a closure evaluated at scrape time (`Prometheus::addGauge(...)->value(fn () => ...)`), outside any brand context — query with `withoutBrandScope()` and group by brand yourself.

## Counters and histograms report storage failures; only names and labels throw
`Metrics::increment()` and `observe()` validate the metric name and its labels against the catalogue and throw on a mismatch — that is a programmer error and should fail loudly. A Redis failure during the actual write is caught and reported instead of propagated, so an event being counted can never abort the request or job that produced it. A recorder therefore needs no try/catch of its own; it just calls `Metrics`.

## Labels are bounded sets: brand slug, provider, queue, route name, outcome. Never ids, amounts per person, free text
Cardinality is series in Redis and a write per event. Route names on the duration histogram come from the allowlist in `config/prometheus.php`; everything else is `other`.

## Alerts are warning or critical, carry team=konventix, a summary and a runbook anchor
The platform blackholes `info`. The anchor is `docs/operations.md#<alertname lowercased>` and the section must exist. Rule files use `__RELEASE__` and `__NAMESPACE__`; the chart substitutes them, `render.sh` does for local runs. Test locally with `contrib/observability/render.sh konventix konventix /tmp/rules && docker run --rm -v /tmp/rules:/rules victoriametrics/vmalert-tool:v1.150.0 unittest --files=/rules/konventix.rules.test.yaml`.

## Dashboards reference datasource uids VictoriaMetrics and VictoriaLogs only, and are generated
Edit the `.mjs` source, not the JSON; CI runs `npm run observability:check`. On the local rig the `VictoriaMetrics` uid is the compose Prometheus; VictoriaLogs panels have no local backend.

## Log context is Context, and the summary records are the access log
`RequestContext` sets release, route or job or command, brand and user_id; they ride into queue payloads with the correlation id. `http.request`, `queue.job` and `schedule.task` records are what the Logs dashboard reads; keep their field names. Never log a name, an e-mail address or a payload.

## The scheduler role must stay quiet
`App\Console\Commands\ScheduleWorkQuietly` replaces `schedule:work`; the framework's version glues "Running [...]" console output onto JSON records. Scheduled task outcomes are `schedule.task` records.
