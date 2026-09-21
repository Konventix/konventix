import { dashboard, row, stat, timeseries, stateTimeline, prom, textbox, thresholds } from './lib.mjs';

const J = 'job="$release"';
const K = 'namespace="$namespace", pod=~"$release-.*"';

const healthMappings = [
    { type: 'value', options: { 0: { text: 'ok', color: 'green' }, 1: { text: 'warning', color: 'orange' }, 2: { text: 'failed', color: 'red' }, 3: { text: 'crashed', color: 'red' }, 4: { text: 'skipped', color: 'blue' } } },
];

export default dashboard({
    uid: 'konventix-platform',
    title: 'Konventix Platform',
    description: 'HTTP, queues, health, runtime and database for one release. Source: konventix-poc contrib/observability (ADR-0059).',
    tags: ['konventix', 'platform'],
    variables: [textbox('namespace', 'konventix', 'Namespace'), textbox('release', 'konventix', 'Helm release')],
    rows: [
        row('Right now', [
            stat('Version', [prom(`konventix_build_info{${J}}`, '{{version}}')], { textMode: 'name', description: 'The deployed image tag, from the series label.', w: 4 }),
            stat('Requests per second', [prom(`sum(rate(konventix_http_requests_total{${J}}[5m]))`)], { decimals: 1 }),
            stat('5xx ratio', [prom(`sum(rate(konventix_http_requests_total{${J}, status=~"5.."}[5m])) / sum(rate(konventix_http_requests_total{${J}}[5m]))`)], { unit: 'percentunit', thresholds: thresholds('green', [0.01, 'orange'], [0.05, 'red']) }),
            stat('Checkout p95', [prom(`histogram_quantile(0.95, sum by (le) (rate(konventix_http_request_duration_seconds_bucket{${J}, route="commerce.cart.checkout"}[10m])))`)], { unit: 's', thresholds: thresholds('green', [1, 'orange'], [3, 'red']) }),
            stat('Oldest pending job', [prom(`max(konventix_queue_oldest_pending_job_age{${J}})`)], { unit: 's', thresholds: thresholds('green', [30, 'orange'], [60, 'red']) }),
            stat('Errors logged (range)', [prom(`sum(increase(konventix_log_records_total{${J}, level=~"error|critical|alert|emergency"}[$__range]))`)], { thresholds: thresholds('green', [1, 'red']) }),
        ]),
        row('HTTP', [
            timeseries('Requests per second by status class', [prom(`sum by (class) (label_replace(rate(konventix_http_requests_total{${J}}[5m]), "class", "$1xx", "status", "(.).."))`, '{{class}}')], { stack: true }),
            timeseries('Latency percentiles by route', [
                prom(`histogram_quantile(0.5, sum by (le, route) (rate(konventix_http_request_duration_seconds_bucket{${J}}[5m])))`, 'p50 {{route}}'),
                prom(`histogram_quantile(0.95, sum by (le, route) (rate(konventix_http_request_duration_seconds_bucket{${J}}[5m])))`, 'p95 {{route}}'),
            ], { unit: 's' }),
            timeseries('5xx per minute by route', [prom(`sum by (route) (rate(konventix_http_requests_total{${J}, status=~"5.."}[5m])) * 60`, '{{route}}')]),
        ]),
        row('Queues', [
            timeseries('Workload per queue', [prom(`konventix_horizon_current_workload{${J}}`, '{{queue}}')]),
            timeseries('Oldest pending job age', [prom(`konventix_queue_oldest_pending_job_age{${J}}`, '{{queue}}')], { unit: 's' }),
            timeseries('Jobs per minute: processed and failed', [
                prom(`sum by (queue) (rate(konventix_queue_jobs_processed_total{${J}}[5m])) * 60`, '{{queue}} processed'),
                prom(`sum by (queue) (rate(konventix_queue_jobs_failed_total{${J}}[5m])) * 60`, '{{queue}} failed'),
            ]),
            timeseries('Horizon processes per queue', [prom(`konventix_horizon_current_processes{${J}}`, '{{queue}}')]),
            stat('Horizon', [prom(`konventix_horizon_status{${J}}`)], { w: 4, thresholds: thresholds('red', [1, 'green']), description: '1 running, 0 stopped (Spatie collector).' }),
            stat('Master supervisors', [prom(`konventix_horizon_master_supervisors{${J}}`)], { w: 4, thresholds: thresholds('red', [1, 'green']) }),
            stat('Jobs per minute', [prom(`konventix_horizon_jobs_per_minute{${J}}`)], { w: 4 }),
            stat('Recent jobs', [prom(`konventix_horizon_recent_jobs{${J}}`)], { w: 4, description: 'Horizon\'s trailing window.' }),
            stat('Recent failed jobs', [prom(`konventix_horizon_failed_recent_jobs{${J}}`)], { w: 4, thresholds: thresholds('green', [1, 'red']) }),
            stat('Failed jobs per hour', [prom(`konventix_horizon_failed_jobs_per_hour{${J}}`)], { w: 4, thresholds: thresholds('green', [1, 'red']) }),
            timeseries('Queue sizes', [
                prom(`konventix_queue_size{${J}}`, '{{queue}} size'),
                prom(`konventix_queue_pending_jobs{${J}}`, '{{queue}} pending'),
                prom(`konventix_queue_reserved_jobs{${J}}`, '{{queue}} reserved'),
                prom(`konventix_queue_delayed_jobs{${J}}`, '{{queue}} delayed'),
            ], { w: 24 }),
        ]),
        row('Health', [
            stateTimeline('Health checks', [prom(`konventix_health_check_status{${J}}`, '{{check}}')], { mappings: healthMappings }),
            timeseries('Schedule heartbeat age', [prom(`time() - konventix_schedule_last_heartbeat_timestamp_seconds{${J}}`)], { unit: 's', w: 12, description: 'KonventixScheduleStale fires at 300 s.' }),
            timeseries('Log records per minute by level', [prom(`sum by (level) (rate(konventix_log_records_total{${J}}[5m])) * 60`, '{{level}}')], { w: 12 }),
        ]),
        row('Consent and conditions', [
            stat('Prompts', [prom(`sum(increase(konventix_consent_prompts_total{${J}}[$__range]))`)], { description: 'Times somebody was asked to agree to something, in the selected range (ADR-0066).' }),
            stat('Grants', [prom(`sum(increase(konventix_consent_grants_total{${J}}[$__range]))`)], { description: 'Agreements recorded. Prompts and grants share one surface set so the two are comparable.' }),
            stat('Not agreed', [prom(`1 - sum(increase(konventix_consent_grants_total{${J}}[$__range])) / sum(increase(konventix_consent_prompts_total{${J}}[$__range]))`)], { unit: 'percentunit', thresholds: thresholds('green', [0.5, 'orange'], [0.9, 'red']), description: 'Derived from prompts against grants (D-CPL-17); the interstitial\'s "Not now" has no counter of its own. KonventixConsentGateBlocking fires when prompts run with no grant at all.' }),
            stat('Outstanding contract subjects', [prom(`sum(konventix_outstanding_contract_subjects{${J}})`)], { description: 'People who still owe agreement to a published contract text.' }),
            stat('Withdrawals', [prom(`sum(increase(konventix_consent_withdrawals_total{${J}}[$__range]))`)]),
            stat('Door refusals', [prom(`sum(increase(konventix_entry_condition_decisions_total{${J}, state="refused", source="door"}[$__range]))`)], { thresholds: thresholds('green', [1, 'orange']), description: 'KonventixEntryConditionRefusalsAtDoor fires above three in ten minutes.' }),
            timeseries('Prompts per hour by surface and reason', [prom(`sum by (surface, reason) (increase(konventix_consent_prompts_total{${J}}[1h]))`, '{{surface}} {{reason}}')], { w: 12 }),
            timeseries('Grants against prompts per hour', [
                prom(`sum by (surface) (increase(konventix_consent_prompts_total{${J}}[1h]))`, '{{surface}} prompted'),
                prom(`sum by (surface) (increase(konventix_consent_grants_total{${J}}[1h]))`, '{{surface}} granted'),
            ], { w: 12, description: 'The refusal rate is the gap between the two — it is derived, never counted (D-CPL-17).' }),
            timeseries('Bound-policy matches per hour by kind', [prom(`sum by (brand, kind) (increase(konventix_policy_binding_matches_total{${J}}[1h]))`, '{{brand}} {{kind}}')], { w: 8, description: 'Whether a bound text is actually being asked for. The kind is a label; the target never is.' }),
            timeseries('Outstanding contract subjects', [prom(`konventix_outstanding_contract_subjects{${J}}`, '{{brand}}')], { w: 8 }),
            timeseries('Entry conditions per hour', [
                prom(`sum by (verification) (increase(konventix_entry_condition_assertions_total{${J}}[1h]))`, 'asserted {{verification}}'),
                prom(`sum by (state, source) (increase(konventix_entry_condition_decisions_total{${J}}[1h]))`, '{{state}} at the {{source}}'),
            ], { w: 8 }),
        ]),
        row('Runtime', [
            timeseries('CPU per role', [prom(`sum by (container) (rate(container_cpu_usage_seconds_total{${K}, container!="", container!="POD"}[5m]))`, '{{container}}')], { unit: 'short' }),
            timeseries('Memory per role', [prom(`sum by (container) (container_memory_working_set_bytes{${K}, container!="", container!="POD"})`, '{{container}}')], { unit: 'bytes' }),
            timeseries('Container restarts (30m)', [prom(`sum by (pod) (increase(kube_pod_container_status_restarts_total{${K}}[30m]))`, '{{pod}}')]),
            timeseries('Unavailable replicas', [prom(`kube_deployment_status_replicas_unavailable{namespace="$namespace", deployment=~"$release-.*"}`, '{{deployment}}')]),
        ]),
        row('Database', [
            stat('Hours since last backup', [prom(`(time() - max(cnpg_collector_last_available_backup_timestamp{namespace="$namespace", pod=~"$release-db-.*"})) / 3600`)], { unit: 'h', decimals: 1, thresholds: thresholds('green', [24, 'orange'], [26, 'red']), w: 6 }),
            stat('WAL archiving', [prom(`max(cnpg_pg_stat_archiver_last_failed_time{namespace="$namespace", pod=~"$release-db-.*"} > bool cnpg_pg_stat_archiver_last_archived_time{namespace="$namespace", pod=~"$release-db-.*"})`)], { w: 6, thresholds: thresholds('green', [1, 'red']), description: '1 when the last archive attempt failed after the last success.' }),
            timeseries('Replication lag', [prom(`cnpg_pg_replication_lag{namespace="$namespace", pod=~"$release-db-.*"}`, '{{pod}}')], { unit: 's', w: 12 }),
        ]),
    ],
});
