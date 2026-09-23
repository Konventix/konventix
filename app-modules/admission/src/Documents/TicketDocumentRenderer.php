<?php

declare(strict_types=1);

namespace Konventix\Admission\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionSeat;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Models\Person;
use Konventix\Admission\Support\TicketReference;
use Konventix\Platform\Documents\DocumentWatermark;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorDateTimeFormat;
use Konventix\Platform\Operators\OperatorSettings;

/**
 * Renders the ticket PDF (GitLab #72 §4): one A4 portrait page per
 * admission, mirroring `BadgeDocumentRenderer` — bytes out, nothing
 * stored, every image inlined as a `data:` URI because dompdf ships with
 * remote fetching disabled.
 *
 * `$admissions` holds admissions of a single brand (§4.1), so the brand
 * and the operator's legal name are resolved once from the first
 * admission rather than per page. Both are read through plain queries,
 * never `$admission->brand`/`$brand->operator` (see Task 19's note on
 * `Model::shouldBeStrict()`).
 *
 * The operator's legal name comes from `OperatorSettings::sellerForBrand()`
 * (ADR-0004) — the same sanctioned doorway Commerce, Compliance and
 * Messaging already use to reach operator data without importing
 * `Konventix\Platform\Models\Operator` across the module boundary. It
 * works from a brand host during a participant request the same as it
 * does from a queue worker: it resolves the operator through the brand
 * row itself, never through `OperatorContext` (which is only ever set on
 * the admin host, ADR-0029).
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
 *
 * Every picture is inlined as base64, so what is inlined decides the
 * render time: the brand logo and each distinct banner are resolved once
 * per file, not once per page, and both are asked for through
 * `MediaLibrary::dataUriForPreferring()` — the library's generated
 * variants first (`ticket_banner`, the band-shaped strip Platform cuts at
 * upload; `thumb`), the original only for a row that has none. The
 * signing key behind every page's QR is likewise read once per render,
 * memoised inside `IssueAdmissionToken` for that instance's lifetime.
 */
final class TicketDocumentRenderer
{
    /**
     * dompdf rasterises a `background-image` — the banner band, since the
     * template's cover crop — into a bitmap of the box's size at the
     * document's DPI and embeds that, once per document, so the DPI and
     * not the picture decides how sharp the banner prints. 150 makes the
     * 210mm band exactly 1240px wide: `ThumbnailGenerator`'s
     * `ticket_banner` strip maps onto it 1:1 and nothing is resampled or
     * upscaled. Every other length in the template is in mm or pt, so the
     * rest of the page is unmoved; the badge and the invoice keep dompdf's
     * default 96.
     */
    public const int DPI = 150;

    public function __construct(
        private readonly MediaLibrary $mediaLibrary,
        private readonly OperatorDateTimeFormat $dates,
        private readonly IssueAdmissionToken $issueAdmissionToken,
        private readonly DocumentWatermark $watermark,
        private readonly OperatorSettings $operatorSettings,
        private readonly TicketQr $qr,
    ) {}

    /**
     * @param  Collection<int, Admission>  $admissions
     */
    public function render(Collection $admissions): string
    {
        $brandId = $admissions->first()?->brand_id;
        $brand = $brandId === null ? null : Brand::query()->find($brandId);
        $operatorLegalName = $brandId === null ? null : $this->operatorSettings->sellerForBrand($brandId)->legalName;
        // Once for the whole file, not once per page: every page of a
        // buyer's file shows the same mark, and each resolution is a row
        // lookup plus a storage read (three round trips on S3). The 480px
        // `thumb` is plenty for a 38mm masthead logo and the 6%-opacity
        // brand mark; a row without one inlines the original.
        $brandLogo = $brand?->logo_media_id === null
            ? null
            : $this->mediaLibrary->dataUriForPreferring($brand->logo_media_id, ['thumb']);

        $eventSummaries = $this->eventSummariesFor($admissions, $brandId);
        $people = $this->peopleFor($admissions);
        $seats = $this->seatsFor($admissions);
        // One resolution per distinct banner, not per page: a group's
        // twenty tickets share one event and one picture (#292 §5a). The
        // `ticket_banner` variant is the band's own shape at 1240px, so the
        // template's `background-size: cover` has nothing left to crop.
        $banners = $eventSummaries
            ->pluck('banner_media_id')
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $mediaId): array => [
                $mediaId => $this->mediaLibrary->dataUriForPreferring($mediaId, ['ticket_banner', 'thumb']),
            ]);

        $pages = $admissions->map(fn (Admission $admission): array => $this->pageDataFor(
            $admission,
            $brand,
            $brandLogo,
            $operatorLegalName,
            $eventSummaries->get($admission->event_id),
            $admission->holder_user_id === null ? null : $people->get($admission->holder_user_id),
            $seats->get($admission->id),
            $banners->get($eventSummaries->get($admission->event_id)->banner_media_id ?? ''),
        ))->all();

        return Pdf::loadView('admission::tickets.document', ['pages' => $pages])
            ->setPaper('a4', 'portrait')
            ->setOption('dpi', self::DPI)
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
     * @param  Collection<int, Admission>  $admissions
     * @return Collection<string, string> place label keyed by admission id
     */
    private function seatsFor(Collection $admissions): Collection
    {
        return AdmissionSeat::withoutBrandScope()
            ->whereIn('admission_id', $admissions->pluck('id'))
            ->pluck('place_label', 'admission_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function pageDataFor(Admission $admission, ?Brand $brand, ?string $brandLogo, ?string $operatorLegalName, ?EventSummary $event, ?Person $holder, ?string $seatLabel, ?string $banner): array
    {
        // Null when nobody holds the ticket yet: the view prints the
        // translated "Unassigned" in its own italic style.
        $holderName = $holder?->display_name;
        $role = str_replace('_', ' ', $admission->role);

        // §4.3: holder name, event name, venue, operator legal name, role
        // and the admission id, in that order. Nulls (a missing summary,
        // no venue, no operator) are dropped rather than printed as gaps.
        $forensicLines = array_values(array_filter([
            $holderName ?? (string) __('Unassigned'),
            $event?->name,
            $event?->venue_label,
            $operatorLegalName,
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
            // The seat (#292 §5) and the banner (§5a): both from this
            // module's own read models, both optional, both merely printed.
            'seatLabel' => $seatLabel,
            // ADR-0066 §9.3, D-ADM-06/07: what the door will ask for, so
            // nobody arrives without it. The condition's own name and the
            // hint that names the document — never the assertion sentence
            // the registrant ticked, which is a statement about a person and
            // belongs in their own export, not on a sheet of paper handed to
            // a stranger (spec §11). **The badge carries none of this**
            // (D-ADM-07); `BadgeDocumentRenderer` is untouched.
            'entryConditions' => $this->entryConditionLinesFor($admission),
            'banner' => $banner,
            'doorCode' => TicketReference::of($admission->id),
            'qr' => $this->qr->dataUri($this->issueAdmissionToken->current($admission)),
            'operatorLegalName' => $operatorLegalName,
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

    /**
     * The stamped list as the page prints it. Opaque scalars in, two strings
     * out: this renderer reads no Registration table and knows nothing about
     * an assertion. A row with no label is dropped rather than printed as a
     * gap, exactly as the forensic lines are.
     *
     * @return list<array{label: string, documentHint: string|null}>
     */
    private function entryConditionLinesFor(Admission $admission): array
    {
        $lines = [];

        foreach ($admission->entry_conditions ?? [] as $condition) {
            $label = trim($condition['label']);

            if ($label === '') {
                continue;
            }

            $hint = trim($condition['document_hint'] ?? '');

            $lines[] = ['label' => $label, 'documentHint' => $hint === '' ? null : $hint];
        }

        return $lines;
    }

    private function eventWhenFor(?EventSummary $event): ?string
    {
        if ($event === null) {
            return null;
        }

        // §4.2: wall clock as stored (ADR-0027), never converted — `starts_at`/
        // `ends_at` carry no offset, so handing a zone to `dateTime()` would
        // shift the printed time instead of merely labelling it. The event
        // landing page prints the same two columns the same way.
        return sprintf(
            '%s – %s (%s)',
            $this->dates->dateTime($event->starts_at),
            $this->dates->dateTime($event->ends_at),
            $event->timezone,
        );
    }

    private function validRangeFor(Admission $admission): string
    {
        if ($admission->valid_from === null || $admission->valid_until === null) {
            return __('All event days');
        }

        return $this->dates->date($admission->valid_from).' – '.$this->dates->date($admission->valid_until);
    }
}
