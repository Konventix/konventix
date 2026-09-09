# Observability sources

The application's dashboards and alert rules, as source (ADR-0059). The
Helm chart (`konventix-helm`) vendors a copy with
`contrib/sync-observability.sh` and renders them as `GrafanaDashboard` and
`VMRule` objects; its CI refuses a copy that differs from the application
tag the chart pins.

- `src/` holds the dashboard sources: `lib.mjs` (panel builders and
  layout), one file per dashboard. `npm run observability:build` writes
  `dashboards/*.json`; `npm run observability:check` fails when a
  committed file is stale (CI runs it). Edit the source, not the JSON.
- `alerts/konventix.rules.yaml` is a Prometheus rule file with two tokens,
  `__RELEASE__` (Helm release name) and `__NAMESPACE__`, which the chart
  substitutes. `alerts/tests/` are vmalert-tool unit tests.
- `render.sh [release] [namespace] <outdir>` substitutes the tokens for a
  local run:

      contrib/observability/render.sh konventix konventix /tmp/rules
      docker run --rm -v /tmp/rules:/rules victoriametrics/vmalert-tool:v1.150.0 \
        unittest --files=/rules/konventix.rules.test.yaml

Dashboards reference datasource uids `VictoriaMetrics` and `VictoriaLogs`
only; on the local rig (`vendor/bin/sail up prometheus grafana`) the first
is the compose Prometheus, the second has no backend. Alerts are
`warning` or `critical` (the platform blackholes `info`), carry
`team: konventix`, and name a section of `docs/operations.md`.
`tests/Feature/ObservabilityCoverageTest.php` holds all of this to the
metric catalogue. A dashboard edited in Grafana is drift and is reverted.
