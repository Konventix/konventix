<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Konventix\Admission\Http\Middleware\RecordTicketRenderMetrics;
use Konventix\Platform\Brands\BrandSlugs;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * `admission_tokens_superseded_total` is already exercised end-to-end in
 * `AdmissionTokenStabilityTest.php` (Task 10). This file covers the second
 * counter this module owns, `admission_tickets_rendered_total` (ADR-0059,
 * design §9, Task 33), through a probe route rather than the real ticket
 * routes — following `WebhookMetricsTest`'s shape — since what is under
 * test is the middleware's page-counting, not `TicketDocumentRenderer`.
 */
function metricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

it('counts ticket pages served, once per rendered response', function (): void {
    $brand = $this->withBrand();

    Route::middleware(RecordTicketRenderMetrics::class)
        ->get('/probe/ticket', function (): Response {
            request()->attributes->set('konventix_ticket_pages', 3);

            return response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']);
        });

    $this->get('/probe/ticket')->assertOk();

    expect(metricsRendered())->toContain('konventix_admission_tickets_rendered_total{brand="'.BrandSlugs::of($brand->getKey()).'"} 3');
});

it('defaults to one page when nothing was stamped', function (): void {
    $brand = $this->withBrand();

    Route::middleware(RecordTicketRenderMetrics::class)
        ->get('/probe/ticket-unstamped', fn (): Response => response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']));

    $this->get('/probe/ticket-unstamped')->assertOk();

    expect(metricsRendered())->toContain('konventix_admission_tickets_rendered_total{brand="'.BrandSlugs::of($brand->getKey()).'"} 1');
});

it('does not count a failed render', function (): void {
    $this->withBrand();

    Route::middleware(RecordTicketRenderMetrics::class)
        ->get('/probe/ticket-failed', fn (): Response => response('nope', 500));

    $this->get('/probe/ticket-failed')->assertStatus(500);

    expect(metricsRendered())->not->toContain('konventix_admission_tickets_rendered_total');
});

it('is wired onto the real ticket routes', function (): void {
    expect(Route::getRoutes()->getByName('admission.tickets.buyer')?->gatherMiddleware())->toContain(RecordTicketRenderMetrics::class)
        ->and(Route::getRoutes()->getByName('admission.tickets.holder')?->gatherMiddleware())->toContain(RecordTicketRenderMetrics::class)
        ->and(Route::getRoutes()->getByName('admission.tickets.mine')?->gatherMiddleware())->not->toContain(RecordTicketRenderMetrics::class);
});
