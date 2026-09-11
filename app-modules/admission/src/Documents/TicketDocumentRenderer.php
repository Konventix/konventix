<?php

declare(strict_types=1);

namespace Konventix\Admission\Documents;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Models\Person;
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
 * Brand is read through a plain query, never `$admission->brand` (see
 * Task 19's note on `Model::shouldBeStrict()`).
 *
 * This task adds the holder band: the QR built from
 * `IssueAdmissionToken::current()` — never `rotate()`, which is a write
 * path only a claim or a reassignment may call — the holder's name or
 * "Unassigned", the role, the valid-days range and a door-typeable short
 * code. Task 21 adds the footer band and the first two watermark layers,
 * Task 22 the TESTSYSTEM layer.
 */
final class TicketDocumentRenderer
{
    /**
     * The QR's nominal size (§4.2). Same technique as
     * `BadgeDocumentRenderer::qrDataUri()` — an inline SVG needs no
     * imagick or gd and dompdf renders it natively — at the ticket's own
     * size rather than the badge's smaller one.
     */
    private const int QR_SIZE_PX = 320;

    public function __construct(
        private readonly MediaLibrary $mediaLibrary,
        private readonly OperatorDateTimeFormat $dates,
        private readonly IssueAdmissionToken $issueAdmissionToken,
    ) {}

    /**
     * @param  Collection<int, Admission>  $admissions
     */
    public function render(Collection $admissions): string
    {
        $brandId = $admissions->first()?->brand_id;
        $brand = $brandId === null ? null : Brand::query()->find($brandId);

        $eventSummaries = $this->eventSummariesFor($admissions, $brandId);
        $people = $this->peopleFor($admissions);

        $pages = $admissions->map(fn (Admission $admission): array => $this->pageDataFor(
            $admission,
            $brand,
            $eventSummaries->get($admission->event_id),
            $admission->holder_user_id === null ? null : $people->get($admission->holder_user_id),
        ))->all();

        return Pdf::loadView('admission::tickets.document', ['pages' => $pages])
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @param  Collection<int, Admission>  $admissions
     * @return Collection<string, EventSummary> keyed by event_id
     */
    private function eventSummariesFor(Collection $admissions, ?string $brandId): Collection
    {
        if ($brandId === null) {
            return collect();
        }

        return EventSummary::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereIn('event_id', $admissions->pluck('event_id')->unique())
            ->get()
            ->keyBy('event_id');
    }

    /**
     * One query for every holder this run needs. `Person` is Admission's
     * own projection of Identity's account events (§13 amendment "the
     * holder-name fallback drops the billing recipient") — this module may
     * not read Identity's `users` table (ADR-0004).
     *
     * @param  Collection<int, Admission>  $admissions
     * @return Collection<string, Person>
     */
    private function peopleFor(Collection $admissions): Collection
    {
        $holderIds = $admissions->pluck('holder_user_id')->filter()->unique()->values();

        if ($holderIds->isEmpty()) {
            return collect();
        }

        return Person::query()->whereIn('id', $holderIds)->get()->keyBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function pageDataFor(Admission $admission, ?Brand $brand, ?EventSummary $event, ?Person $holder): array
    {
        return [
            'brandLogo' => $brand?->logo_media_id === null ? null : $this->mediaLibrary->dataUriFor($brand->logo_media_id),
            'eventName' => $event?->name,
            'eventWhen' => $this->eventWhenFor($event),
            'venueLabel' => $event?->venue_label,
            'holderName' => $holder->display_name ?? 'Unassigned',
            'role' => str_replace('_', ' ', $admission->role),
            'validRange' => $this->validRangeFor($admission),
            'doorCode' => $this->doorCode($admission->id),
            'qr' => $this->qrDataUri($this->issueAdmissionToken->current($admission)),
        ];
    }

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

    /**
     * The days the token covers (§4.2). `valid_from`/`valid_until` null —
     * the common case — means the admission carries no window narrower
     * than the whole event, and the ticket says so in words rather than
     * printing an empty range.
     */
    private function validRangeFor(Admission $admission): string
    {
        if ($admission->valid_from === null || $admission->valid_until === null) {
            return 'All event days';
        }

        return $this->dates->date($admission->valid_from).' – '.$this->dates->date($admission->valid_until);
    }

    /**
     * A door-typeable fallback for when a scan fails and there is no other
     * way to look the holder up (mirrors the badge's `.ref` line,
     * ADR-0033) — not the full 26-character ULID printed there: its first
     * 10 characters are a shared, low-entropy timestamp, so the last 8 —
     * the random tail — carry the identifying bits, split into two groups
     * of four to read aloud at a door.
     */
    private function doorCode(string $admissionId): string
    {
        $tail = strtoupper(substr($admissionId, -8));

        return substr($tail, 0, 4).'-'.substr($tail, 4, 4);
    }

    /**
     * @see BadgeDocumentRenderer::qrDataUri() the same technique — SVG,
     * not raster: no imagick or gd, crisp at any print size, dompdf
     * renders it natively.
     */
    private function qrDataUri(string $token): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::QR_SIZE_PX, 0), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($token));
    }
}
