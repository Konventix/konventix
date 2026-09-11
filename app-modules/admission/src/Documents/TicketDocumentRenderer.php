<?php

declare(strict_types=1);

namespace Konventix\Admission\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EventSummary;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorDateTimeFormat;

/**
 * Renders the ticket PDF (GitLab #72 §4): one A4 portrait page per
 * admission, mirroring `BadgeDocumentRenderer` — bytes out, nothing
 * stored, every image inlined as a `data:` URI because dompdf ships with
 * remote fetching disabled.
 *
 * `$admissions` holds admissions of a single brand (§4.1), so the brand is
 * resolved once from the first admission rather than per page.
 *
 * Brand is read through a plain query, never `$admission->brand`:
 * `Model::shouldBeStrict()` is on in non-production and treats *any*
 * unloaded relation access as a violation, not only a repeated one inside
 * a loop — exactly what `InvoiceDocumentRenderer` already routes around
 * with `Order::withoutBrandScope()->whereKey(...)` instead of
 * `$invoice->order`.
 *
 * This task lays down the page loop and the event band only. Task 20 adds
 * the holder band and the QR, Task 21 the footer band and the first two
 * watermark layers, Task 22 the TESTSYSTEM layer — none of them change
 * this class's constructor or `render()` signature, only extend what
 * `pageDataFor()` builds and what the view shows.
 */
final class TicketDocumentRenderer
{
    public function __construct(
        private readonly MediaLibrary $mediaLibrary,
        private readonly OperatorDateTimeFormat $dates,
    ) {}

    /**
     * @param  Collection<int, Admission>  $admissions
     */
    public function render(Collection $admissions): string
    {
        $brandId = $admissions->first()?->brand_id;
        $brand = $brandId === null ? null : Brand::query()->find($brandId);

        $eventSummaries = $this->eventSummariesFor($admissions, $brandId);

        $pages = $admissions->map(fn (Admission $admission): array => $this->pageDataFor(
            $admission,
            $brand,
            $eventSummaries->get($admission->event_id),
        ))->all();

        return Pdf::loadView('admission::tickets.document', ['pages' => $pages])
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * One query for every event this run needs, not one per admission.
     * `EventSummary` is Admission's own read model (§13 amendment "the
     * calendar builder moves to Platform...") — this module may not read
     * Event's tables (ADR-0004).
     *
     * @param  Collection<int, Admission>  $admissions
     * @return Collection<string, EventSummary> keyed by event_id
     */
    private function eventSummariesFor(Collection $admissions, ?string $brandId): Collection
    {
        if ($brandId === null) {
            return collect();
        }

        // Explicit brand, not ambient context (ADR-0010 escape hatch): a
        // ticket can render from a queue worker or an operator console
        // with no brand resolved from the request.
        return EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereIn('event_id', $admissions->pluck('event_id')->unique())
            ->get()
            ->keyBy('event_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function pageDataFor(Admission $admission, ?Brand $brand, ?EventSummary $event): array
    {
        return [
            'brandLogo' => $brand?->logo_media_id === null ? null : $this->mediaLibrary->dataUriFor($brand->logo_media_id),
            'eventName' => $event?->name,
            'eventWhen' => $this->eventWhenFor($event),
            'venueLabel' => $event?->venue_label,
        ];
    }

    /**
     * The event's own wall clock, in the event's own timezone (§4.2:
     * "local start and end... with the timezone named") — never converted
     * to the operator's clock, which is what `OperatorDateTimeFormat`
     * would do by default for an instant with no zone given.
     */
    private function eventWhenFor(?EventSummary $event): ?string
    {
        if ($event === null) {
            return null;
        }

        return sprintf(
            '%s – %s (%s)',
            $this->dates->dateTime($event->starts_at, $event->timezone),
            $this->dates->dateTime($event->ends_at, $event->timezone),
            $event->timezone,
        );
    }
}
