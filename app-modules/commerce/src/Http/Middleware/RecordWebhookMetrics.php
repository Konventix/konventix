<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Konventix\Commerce\Metrics\CommerceMetrics;
use Konventix\Platform\Observability\Metrics;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts and times every PSP webhook (ADR-0059). The provider is the last
 * segment of the route name (`api.commerce.webhooks.stripe`), so a new
 * provider needs no change here. Outcome follows the status the handler
 * answered: 2xx accepted, 4xx rejected (bad signature, unknown payment),
 * 5xx error. An accepted webhook also stamps the provider's last-received
 * time, which is what KonventixWebhookSilence watches (#168).
 */
final class RecordWebhookMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        $this->record($request, $response->getStatusCode(), microtime(true) - $startedAt);

        return $response;
    }

    private function record(Request $request, int $status, float $seconds): void
    {
        $name = (string) $request->route()?->getName();
        $provider = substr($name, (int) strrpos($name, '.') + 1) ?: 'unknown';

        $outcome = match (true) {
            $status < 300 => 'accepted',
            $status < 500 => 'rejected',
            default => 'error',
        };

        $metrics = app(Metrics::class);
        $metrics->increment('payment_webhooks_total', ['provider' => $provider, 'outcome' => $outcome]);
        $metrics->observe('payment_webhook_duration_seconds', $seconds, ['provider' => $provider]);

        if ($outcome === 'accepted') {
            try {
                // Best-effort stamp: Metrics already contains its own storage failures,
                // this cache write gets the same treatment rather than breaking the request.
                Cache::forever(CommerceMetrics::webhookReceivedKey($provider), now()->getTimestamp());
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
