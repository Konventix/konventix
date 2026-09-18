<?php

declare(strict_types=1);

namespace Konventix\Admission\Metrics;

use Konventix\Platform\Observability\MetricDefinition;
use Konventix\Platform\Observability\MetricKind;

/**
 * Admission's entries in the metric catalogue (ADR-0059, design doc §9).
 * `admission_tokens_superseded_total` is incremented by
 * `Listeners\Metrics\RecordAdmissionTokenMetrics`;
 * `admission_tickets_rendered_total` is incremented by
 * `Http\Middleware\RecordTicketRenderMetrics` on the two ticket-PDF routes
 * — an HTTP-triggered fact, not a domain-state change, so it has no
 * listener. Both live on the `konventix-sale` dashboard's "Tickets and
 * calendars" row alongside Event's `event_calendar_downloads_total`.
 */
final class AdmissionMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition(
                'admission_tokens_superseded_total',
                MetricKind::Counter,
                "Scans refused because the presented token's nonce no longer matches the admission's current one.",
                ['brand'],
            ),

            // Task 33 (design §9): one increment per admission page a
            // ticket route actually served, recorded by
            // Http\Middleware\RecordTicketRenderMetrics.
            new MetricDefinition(
                'admission_tickets_rendered_total',
                MetricKind::Counter,
                'Ticket PDF pages rendered and served.',
                ['brand'],
            ),
            // #292 §9: how tickets change hands — claimed through a link,
            // assigned by the buyer or at the desk, taken back.
            new MetricDefinition(
                'admission_holder_changes_total',
                MetricKind::Counter,
                'Holder changes on admissions, by how they happened.',
                ['brand', 'outcome'],
            ),
            // #292 §9: invitations issued, mailed or not, withdrawn. A
            // `send_failed` that outruns `sent` is the alert.
            new MetricDefinition(
                'admission_claim_invites_total',
                MetricKind::Counter,
                'Claim-link invitations by outcome.',
                ['brand', 'outcome'],
            ),
        ];
    }
}
