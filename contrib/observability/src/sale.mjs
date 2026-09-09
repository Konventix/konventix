import { dashboard, row, stat, timeseries, prom, textbox, thresholds } from './lib.mjs';

const J = 'job="$release"';

export default dashboard({
    uid: 'konventix-sale',
    title: 'Konventix Sale',
    description: 'Orders, payments, webhooks and the waiting room, per brand. Source: konventix-poc contrib/observability (ADR-0059).',
    tags: ['konventix', 'sale'],
    variables: [textbox('release', 'konventix', 'Helm release')],
    refresh: '15s',
    from: 'now-1h',
    rows: [
        row('Right now', [
            stat('Orders placed', [prom(`sum(increase(konventix_orders_total{${J}, outcome="placed"}[$__range]))`)], { description: 'In the selected range.' }),
            stat('Orders paid', [prom(`sum(increase(konventix_orders_total{${J}, outcome="paid"}[$__range]))`)]),
            stat('Orders cancelled', [prom(`sum(increase(konventix_orders_total{${J}, outcome="cancelled"}[$__range]))`)]),
            stat('Paid amount', [prom(`sum by (currency) (increase(konventix_order_paid_amount_cents_total{${J}}[$__range])) / 100`, '{{currency}}')], { unit: 'currencyEUR', decimals: 2 }),
            stat('Payment failure rate', [prom(`sum(increase(konventix_payments_total{${J}, outcome="failed"}[$__range])) / sum(increase(konventix_payments_total{${J}, outcome=~"failed|received"}[$__range]))`)], { unit: 'percentunit', thresholds: thresholds('green', [0.1, 'orange'], [0.3, 'red']) }),
            stat('Payments pending', [prom(`sum(konventix_payments_pending{${J}})`)]),
            stat('Waiting room depth', [prom(`sum(konventix_waiting_room_depth{${J}})`)], { thresholds: thresholds('green', [100, 'orange'], [1000, 'red']) }),
            stat('Admissions per minute', [prom(`sum(rate(konventix_waiting_room_admissions_total{${J}}[5m])) * 60`)], { decimals: 0 }),
        ]),
        row('Orders and payments', [
            timeseries('Orders per minute by outcome', [prom(`sum by (brand, outcome) (rate(konventix_orders_total{${J}}[5m])) * 60`, '{{brand}} {{outcome}}')]),
            timeseries('Payments per minute by provider and outcome', [prom(`sum by (provider, outcome) (rate(konventix_payments_total{${J}}[5m])) * 60`, '{{provider}} {{outcome}}')]),
            timeseries('Pending payments', [prom(`konventix_payments_pending{${J}}`, '{{brand}}')]),
            timeseries('Paid amount per hour', [prom(`sum by (brand, currency) (increase(konventix_order_paid_amount_cents_total{${J}}[1h])) / 100`, '{{brand}} {{currency}}')], { unit: 'currencyEUR' }),
        ]),
        row('Webhooks', [
            timeseries('Webhooks per minute by provider and outcome', [prom(`sum by (provider, outcome) (rate(konventix_payment_webhooks_total{${J}}[5m])) * 60`, '{{provider}} {{outcome}}')]),
            timeseries('Webhook p95 duration', [prom(`histogram_quantile(0.95, sum by (le, provider) (rate(konventix_payment_webhook_duration_seconds_bucket{${J}}[5m])))`, '{{provider}}')], { unit: 's' }),
            timeseries('Seconds since last webhook', [prom(`time() - konventix_payment_webhook_last_received_timestamp_seconds{${J}}`, '{{provider}}')], { unit: 's', description: 'KonventixWebhookSilence fires at 900 s while payments are pending.' }),
        ]),
        row('Messages', [
            timeseries('Messages per minute by channel and outcome', [prom(`sum by (channel, outcome) (rate(konventix_messages_total{${J}}[5m])) * 60`, '{{channel}} {{outcome}}')], { w: 24, description: 'failed is final; retry will be attempted again.' }),
        ]),
        row('Waiting room and stock', [
            timeseries('Waiting room depth', [prom(`konventix_waiting_room_depth{${J}}`, '{{brand}}')]),
            timeseries('Admissions per minute', [prom(`sum by (brand) (rate(konventix_waiting_room_admissions_total{${J}}[5m])) * 60`, '{{brand}}')]),
            timeseries('Seconds since last admission run', [prom(`time() - konventix_waiting_room_last_admission_timestamp_seconds{${J}}`, '{{brand}}')], { unit: 's', description: 'KonventixWaitingRoomStuck fires at 180 s with a non-empty queue.' }),
            timeseries('Carts expired and stock exhausted per hour', [
                prom(`sum by (brand) (increase(konventix_carts_expired_total{${J}}[1h]))`, '{{brand}} carts expired'),
                prom(`sum by (brand) (increase(konventix_stock_exhausted_total{${J}}[1h]))`, '{{brand}} stock exhausted'),
            ]),
        ]),
    ],
});
