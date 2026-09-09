import { dashboard, row, timeseries, logs, logsql, textbox } from './lib.mjs';

// LogsQL stream and field selectors. Field names with dots or slashes are
// quoted. Query-time only: VictoriaLogs already parsed the JSON at ingest.
const app = 'kubernetes.pod_namespace:$namespace AND "kubernetes.pod_labels.app.kubernetes.io/instance":$release';
const ingress = 'kubernetes.pod_namespace:ingress-public AND kubernetes.container_name:controller';

export default dashboard({
    uid: 'konventix-logs',
    title: 'Konventix Logs',
    description: 'Errors, request records and one correlation id end to end, from VictoriaLogs. No local backend on sail. Source: konventix-poc contrib/observability (ADR-0059).',
    tags: ['konventix', 'logs'],
    variables: [
        textbox('namespace', 'konventix', 'Namespace'),
        textbox('release', 'konventix', 'Helm release'),
        textbox('correlation_id', 'paste-an-id', 'Correlation id'),
    ],
    rows: [
        row('Errors', [
            timeseries('Error records by exception class', [logsql(`${app} AND level_name:ERROR | stats by (context.exception.class) count() as errors`)], { w: 12 }),
            timeseries('Warning and error records by container', [logsql(`${app} AND level_name:in(WARNING, ERROR, CRITICAL, ALERT, EMERGENCY) | stats by (kubernetes.container_name) count() as records`)], { w: 12 }),
            logs('Latest error records', [logsql(`${app} AND level_name:in(ERROR, CRITICAL, ALERT, EMERGENCY) | sort by (_time) desc | limit 100`, 'instant')]),
        ]),
        row('Requests', [
            timeseries('Requests by status', [logsql(`${app} AND _msg:"http.request" | stats by (context.status) count() as requests`)], { w: 12 }),
            timeseries('Request duration p50 and p95 by route', [
                logsql(`${app} AND _msg:"http.request" | stats by (context.route) quantile(0.5, context.duration_ms) as p50`),
                logsql(`${app} AND _msg:"http.request" | stats by (context.route) quantile(0.95, context.duration_ms) as p95`),
            ], { w: 12, unit: 'ms' }),
            timeseries('Failed jobs by class', [logsql(`${app} AND _msg:"queue.job" AND context.outcome:failed | stats by (context.job) count() as failed`)], { w: 12 }),
            timeseries('Failed scheduled tasks', [logsql(`${app} AND _msg:"schedule.task" AND level_name:ERROR | stats by (context.command) count() as failed`)], { w: 12 }),
        ]),
        row('One correlation id', [
            logs('Ingress access line, request record, every record of the request and its jobs', [
                logsql(`(${app} AND extra.correlation_id:"$correlation_id") OR (${ingress} AND _msg:~"$correlation_id") | sort by (_time) | limit 500`, 'instant'),
            ], { description: 'Nothing shows until you replace the default correlation id above with a real one: paste the X-Request-ID a user quotes, or the "Reference" from an error page.' }),
        ]),
    ],
});
