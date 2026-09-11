<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts every ticket PDF actually served (ADR-0059, design §9): one
 * increment per rendered page, not per request, because a buyer's file
 * bundles one page per admission into a single response. The controller
 * stamps the page count onto the request as an attribute right before it
 * returns the PDF — the same before/after shape `RecordWebhookMetrics` and
 * the host's `RecordHttpMetrics` use, adapted because this counter's unit
 * is pages, not requests. Wired only onto the two PDF routes (`buyer`,
 * `holder`); `/my/tickets` is a listing page, not a render, and carries no
 * attribute to read.
 *
 * A render failure is a 500, already covered by `KonventixHttp5xxRate` and
 * `KonventixErrorsLogged` (ADR-0059 point 8, design §9) — this counter only
 * fires on success, so it never has to distinguish an error from a render.
 */
final class RecordTicketRenderMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() < 300) {
            $pages = (int) $request->attributes->get('konventix_ticket_pages', 1);
            $brand = app(BrandContext::class)->current();

            // Not `$brand?->slug ?? ...`: Larastan mistypes `current()` here
            // as never-null, which makes the nullsafe form a phpstan false
            // positive (`nullsafe.neverNull`) — an explicit null check
            // sidesteps it without changing the behaviour.
            app(Metrics::class)->increment(
                'admission_tickets_rendered_total',
                ['brand' => $brand !== null ? $brand->slug : BrandSlugs::UNKNOWN],
                (float) max(1, $pages),
            );
        }

        return $response;
    }
}
