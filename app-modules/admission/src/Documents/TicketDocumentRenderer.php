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
use Konventix\Platform\Documents\DocumentWatermark;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Operators\OperatorDateTimeFormat;

/**
 * Renders the ticket PDF (GitLab #72 §4): one A4 portrait page per
 * admission, mirroring `BadgeDocumentRenderer` — bytes out, nothing
 * stored, every image inlined as a `data:` URI because dompdf ships with
 * remote fetching disabled.
 *
 * `$admissions` holds admissions of a single brand (§4.1), so the brand
 * (and its operator) are resolved once from the first admission rather
 * than per page. Both are read through plain queries, never
 * `$admission->brand`/`$brand->operator` (see Task 19's note on
 * `Model::shouldBeStrict()`).
 *
 * This task adds the footer band and the first two of the three watermark
 * layers (§4.3): a low-opacity brand mark and a diagonal forensic repeat,
 * both produced by one call into Platform's `DocumentWatermark::html()`.
 * The forensic lines are composed here, as plain strings — the service
 * itself "knows nothing about admissions". Task 22 adds the third layer,
 * TESTSYSTEM.
 *
 * The operator has no logo field (only a name and a legal name), so its
 * presence on the page is textual only — the footer band and the
 * forensic repeat, never an image.
 */
final class TicketDocumentRenderer
{
    private const int QR_SIZE_PX = 320;

    public function __construct(
        private readonly MediaLibrary $mediaLibrary,
        private readonly OperatorDateTimeFormat $dates,
        private readonly IssueAdmissionToken $issueAdmissionToken,
        private readonly DocumentWatermark $watermark,
    ) {}

    /**
     * @param  Collection<int, Admission>  $admissions
     */
    public function render(Collection $admissions): string
    {
        $brandId = $admissions->first()?->brand_id;
        $brand = $brandId === null ? null : Brand::query()->with('operator')->find($brandId);
        $operator = $brand?->operator;

        $eventSummaries = $this->eventSummariesFor($admissions, $brandId);
        $people = $this->peopleFor($admissions);

        $pages = $admissions->map(fn (Admission $admission): array => $this->pageDataFor(
            $admission,
            $brand,
            $operator,
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
    private function pageDataFor(Admission $admission, ?Brand $brand, ?Operator $operator, ?EventSummary $event, ?Person $holder): array
    {
        $holderName = $holder->display_name ?? 'Unassigned';
        $role = str_replace('_', ' ', $admission->role);
        $brandLogo = $brand?->logo_media_id === null ? null : $this->mediaLibrary->dataUriFor($brand->logo_media_id);

        // §4.3: holder name, event name, venue, operator legal name, role
        // and the admission id, in that order. Nulls (a missing summary,
        // no venue, no operator) are dropped rather than printed as gaps.
        $forensicLines = array_values(array_filter([
            $holderName,
            $event?->name,
            $event?->venue_label,
            $operator?->legal_name,
            $role,
            $admission->id,
        ], fn (?string $line): bool => $line !== null && $line !== ''));

        return [
            'brandLogo' => $brandLogo,
            'eventName' => $event?->name,
            'eventWhen' => $this->eventWhenFor($event),
            'venueLabel' => $event?->venue_label,
            'holderName' => $holderName,
            'role' => $role,
            'validRange' => $this->validRangeFor($admission),
            'doorCode' => $this->doorCode($admission->id),
            'qr' => $this->qrDataUri($this->issueAdmissionToken->current($admission)),
            'operatorLegalName' => $operator?->legal_name,
            'brandName' => $brand?->name,
            'admissionId' => $admission->id,
            // The brand mark and the forensic repeat, deterministically
            // seeded from the admission id so two renders of the same
            // ticket look the same.
            'watermarkHtml' => $this->watermark->html($forensicLines, $brandLogo, $admission->id),
            // §4.3 layer 3: a single diagonal band, empty on production.
            // `DocumentWatermark::testSystemBanner()` asks
            // `DeploymentEnvironment` itself — this renderer never reads
            // `app.env` or calls `env()` (ADR-0053).
            'testSystemHtml' => $this->watermark->testSystemBanner(),
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

    private function validRangeFor(Admission $admission): string
    {
        if ($admission->valid_from === null || $admission->valid_until === null) {
            return 'All event days';
        }

        return $this->dates->date($admission->valid_from).' – '.$this->dates->date($admission->valid_until);
    }

    private function doorCode(string $admissionId): string
    {
        $tail = strtoupper(substr($admissionId, -8));

        return substr($tail, 0, 4).'-'.substr($tail, 4, 4);
    }

    private function qrDataUri(string $token): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::QR_SIZE_PX, 0), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($token));
    }
}
