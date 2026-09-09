// Small Grafana JSON builders (ADR-0059). Panels are placed left to right
// in the order given, wrapping at 24 columns; a row starts a new line with
// a collapsible header. Only the two platform datasources exist:
// VictoriaMetrics (prometheus type) and VictoriaLogs.
export const VM = { type: 'prometheus', uid: 'VictoriaMetrics' };
export const VL = { type: 'victoriametrics-logs-datasource', uid: 'VictoriaLogs' };

export function textbox(name, value, label = name) {
    return {
        type: 'textbox',
        name,
        label,
        query: value,
        current: { text: value, value },
        options: [{ text: value, value, selected: true }],
        hide: 0,
    };
}

export function dashboard({ uid, title, description, tags, variables = [], rows, refresh = '30s', from = 'now-6h' }) {
    return {
        uid,
        title,
        description,
        tags,
        editable: true,
        graphTooltip: 1,
        schemaVersion: 41,
        version: 1,
        refresh,
        time: { from, to: 'now' },
        timezone: 'browser',
        templating: { list: variables },
        annotations: { list: [] },
        links: [],
        panels: layout(rows),
    };
}

export function row(title, panels) {
    return { title, panels };
}

export function prom(expr, legend = '', extra = {}) {
    return { datasource: VM, expr, legendFormat: legend, range: true, ...extra };
}

export function logsql(expr, queryType = 'statsRange', extra = {}) {
    return { datasource: VL, expr, queryType, ...extra };
}

export function stat(title, targets, { unit = 'short', w = 4, h = 4, decimals, thresholds, reduce = 'lastNotNull', textMode = 'value', description } = {}) {
    return panel('stat', title, targets, w, h, {
        description,
        fieldConfig: { defaults: { unit, decimals, thresholds: thresholds ?? greenOnly(), color: { mode: 'thresholds' } }, overrides: [] },
        options: { reduceOptions: { calcs: [reduce], fields: '', values: false }, colorMode: 'value', graphMode: 'none', textMode, orientation: 'auto' },
    });
}

export function timeseries(title, targets, { unit = 'short', w = 12, h = 8, stack = false, min = 0, max, description } = {}) {
    return panel('timeseries', title, targets, w, h, {
        description,
        fieldConfig: {
            defaults: { unit, min, max, color: { mode: 'palette-classic' }, custom: { drawStyle: 'line', lineWidth: 1, fillOpacity: stack ? 30 : 8, stacking: { mode: stack ? 'normal' : 'none', group: 'A' }, spanNulls: false } },
            overrides: [],
        },
        options: { legend: { displayMode: 'list', placement: 'bottom', showLegend: true }, tooltip: { mode: 'multi', sort: 'desc' } },
    });
}

export function stateTimeline(title, targets, { w = 24, h = 6, mappings = [], description } = {}) {
    return panel('state-timeline', title, targets, w, h, {
        description,
        fieldConfig: { defaults: { color: { mode: 'thresholds' }, thresholds: greenOnly(), mappings, custom: { fillOpacity: 70, lineWidth: 0 } }, overrides: [] },
        options: { mergeValues: true, showValue: 'never', alignValue: 'left', rowHeight: 0.9, legend: { displayMode: 'list', placement: 'bottom', showLegend: true } },
    });
}

export function logs(title, targets, { w = 24, h = 12, description } = {}) {
    return panel('logs', title, targets, w, h, {
        description,
        options: { showTime: true, wrapLogMessage: true, prettifyLogMessage: false, enableLogDetails: true, dedupStrategy: 'none', sortOrder: 'Descending' },
    });
}

export function thresholds(...steps) {
    // thresholds('green', [10, 'orange'], [50, 'red'])
    const [base, ...rest] = steps;

    return { mode: 'absolute', steps: [{ color: base, value: null }, ...rest.map(([value, color]) => ({ color, value }))] };
}

function greenOnly() {
    return thresholds('green');
}

function panel(type, title, targets, w, h, body) {
    const refIds = 'ABCDEFGHIJKLMNOP';

    return {
        type,
        title,
        gridPos: { w, h, x: 0, y: 0 },
        targets: targets.map((t, i) => ({ refId: refIds[i], ...t })),
        ...body,
    };
}

function layout(rows) {
    const out = [];
    let id = 1;
    let y = 0;

    for (const r of rows) {
        out.push({ id: id++, type: 'row', title: r.title, collapsed: false, gridPos: { x: 0, y, w: 24, h: 1 }, panels: [] });
        y += 1;
        let x = 0;
        let lineHeight = 0;

        for (const p of r.panels) {
            if (x + p.gridPos.w > 24) {
                x = 0;
                y += lineHeight;
                lineHeight = 0;
            }

            out.push({ ...p, id: id++, gridPos: { ...p.gridPos, x, y } });
            x += p.gridPos.w;
            lineHeight = Math.max(lineHeight, p.gridPos.h);
        }

        y += lineHeight;
    }

    return out;
}
