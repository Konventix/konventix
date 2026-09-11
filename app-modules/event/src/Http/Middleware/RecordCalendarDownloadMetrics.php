<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Observability\Metrics;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts every public calendar-file download (ADR-0059, design §9): one per
 * successful response to `GET /events/{event:slug}/calendar.ics`, following
 * `RecordWebhookMetrics`'s before/after shape. A 404 (an event that is not
 * publicly visible, design §6.2) is not a download and is not counted.
 */
final class RecordCalendarDownloadMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() < 300) {
            $brand = app(BrandContext::class)->current();

            // Not `$brand?->slug ?? ...`: Larastan mistypes `current()` here
            // as never-null, which makes the nullsafe form a phpstan false
            // positive (`nullsafe.neverNull`) — an explicit null check
            // sidesteps it without changing the behaviour.
            app(Metrics::class)->increment(
                'event_calendar_downloads_total',
                ['brand' => $brand !== null ? $brand->slug : BrandSlugs::UNKNOWN],
            );
        }

        return $response;
    }
}
