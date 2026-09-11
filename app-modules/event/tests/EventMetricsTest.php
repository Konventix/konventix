<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Konventix\Event\Http\Middleware\RecordCalendarDownloadMetrics;
use Konventix\Platform\Brands\BrandSlugs;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * `event_calendar_downloads_total` (ADR-0059, design §9, Task 33), through
 * a probe route rather than the real calendar route — following
 * `WebhookMetricsTest`'s and Admission's own `AdmissionMetricsTest`'s
 * shape — since what is under test is the middleware's counting, not
 * `CalendarFile::forEvent()`.
 */
function eventMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

it('counts a successful calendar download once', function (): void {
    $brand = $this->withBrand();

    Route::middleware(RecordCalendarDownloadMetrics::class)
        ->get('/probe/calendar', fn (): Response => response('BEGIN:VCALENDAR', 200, ['Content-Type' => 'text/calendar']));

    $this->get('/probe/calendar')->assertOk();

    expect(eventMetricsRendered())->toContain('konventix_event_calendar_downloads_total{brand="'.BrandSlugs::of($brand->getKey()).'"} 1');
});

it('does not count a 404', function (): void {
    $this->withBrand();

    Route::middleware(RecordCalendarDownloadMetrics::class)
        ->get('/probe/calendar-missing', fn (): Response => response('not found', 404));

    $this->get('/probe/calendar-missing')->assertStatus(404);

    expect(eventMetricsRendered())->not->toContain('konventix_event_calendar_downloads_total');
});

it('is wired onto the real calendar route', function (): void {
    $route = Route::getRoutes()->getByName('event.calendar');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain(RecordCalendarDownloadMetrics::class);
});
