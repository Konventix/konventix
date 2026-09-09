<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Konventix\Platform\Observability\Metrics;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request rate, error rate and latency as the application sees them
 * (ADR-0059): after routing, auth and rendering, before the response leaves
 * the process. What the ingress adds on top is the platform's number.
 *
 * Global, directly after AssignCorrelationId, so an exception the handler
 * rendered still counts under its status. The two diagnostics endpoints and
 * `/up` are left out: a scrape every thirty seconds is not traffic.
 *
 * Recording must never break a request: `Metrics` itself reports and
 * swallows a storage failure, so this middleware carries no try/catch of
 * its own (.ai/rules/observability.md).
 */
final class RecordHttpMetrics
{
    public const array SKIPPED_ROUTES = ['prometheus.default', 'health.json'];

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        // The same start point the http.request summary record in
        // ObservabilityServiceProvider reads (ADR-0059), so the histogram
        // and the summary's duration_ms measure from one place.
        Context::addHidden('request_started_at', $startedAt);

        $response = $next($request);

        $this->record($request, $response->getStatusCode(), microtime(true) - $startedAt);

        return $response;
    }

    public static function histogramRoute(?string $name): string
    {
        if ($name === null) {
            return 'other';
        }

        if (in_array($name, (array) config('prometheus.http.routes', []), true)) {
            return $name;
        }

        foreach ((array) config('prometheus.http.route_prefixes', []) as $prefix) {
            if (str_starts_with($name, (string) $prefix)) {
                return $name;
            }
        }

        return 'other';
    }

    private function record(Request $request, int $status, float $seconds): void
    {
        $name = $request->route()?->getName();

        if ($request->is('up') || in_array($name, self::SKIPPED_ROUTES, true)) {
            return;
        }

        $metrics = app(Metrics::class);

        $metrics->increment('http_requests_total', [
            'method' => $request->method(),
            'status' => (string) $status,
            'route' => $name ?? 'unnamed',
        ]);

        $metrics->observe('http_request_duration_seconds', $seconds, [
            'route' => self::histogramRoute($name),
        ]);
    }
}
