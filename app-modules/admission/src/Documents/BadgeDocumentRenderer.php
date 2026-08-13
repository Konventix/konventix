<?php

declare(strict_types=1);

namespace Konventix\Admission\Documents;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Konventix\Admission\Data\BadgeLayoutData;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\BadgeTemplate;
use Konventix\Platform\Media\MediaLibrary;

/**
 * Renders one admission's badge to PDF bytes, mirroring Commerce's
 * `InvoiceDocumentRenderer` (D-ADM-05: "server-rendered PDF via the existing
 * dompdf pipeline").
 *
 * **Everything the document references is inlined as a `data:` URI.** dompdf
 * ships with remote fetching disabled, so an `http://` image resolves to
 * nothing; enabling remote fetching instead would let any stored media
 * reference trigger an outbound request from the server at render time. Media
 * comes through `MediaLibrary::dataUriFor()` — the library's sanctioned
 * doorway (ADR-0035), widened for this rather than reached around.
 *
 * Returns bytes and stores nothing. A badge is reproducible on demand, unlike
 * an invoice that GoBD requires archived unaltered (ADR-0031), so writing
 * attendee-bearing PDFs to disk would add GDPR surface for no gain.
 */
final class BadgeDocumentRenderer
{
    /**
     * Paper sizes as dompdf's `[x0, y0, x1, y1]` point rectangles — not
     * width/height pairs, which dompdf silently misreads. Badge stock has no
     * named preset, so every size is given explicitly (A6 105×148mm,
     * A7 74×105mm, credit-card 85.6×54mm, at 72dpi).
     *
     * @var array<string, array{float, float, float, float}>
     */
    private const array PAPER = [
        BadgeLayoutData::SIZE_A6 => [0.0, 0.0, 297.64, 419.53],
        BadgeLayoutData::SIZE_A7 => [0.0, 0.0, 209.76, 297.64],
        BadgeLayoutData::SIZE_CREDIT => [0.0, 0.0, 242.65, 153.07],
    ];

    public function __construct(
        private readonly MediaLibrary $mediaLibrary,
    ) {}

    /**
     * `$token` is the KVX1 admission token that becomes the QR. It is passed
     * in rather than minted here so rendering stays a pure formatting step —
     * and so a reprint can reproduce the badge without minting a *new* token
     * and silently stranding the attendee's existing QR.
     */
    public function render(Admission $admission, ?BadgeTemplate $template, ?string $token, ?string $holderName): string
    {
        // An event that never configured a badge still prints one, on the
        // layout defaults.
        $layout = $template === null ? new BadgeLayoutData : $template->layout;

        $paper = self::PAPER[$layout->size] ?? self::PAPER[BadgeLayoutData::SIZE_A6];

        return Pdf::loadView('admission::badges.document', [
            'admission' => $admission,
            'layout' => $layout,
            'holderName' => $holderName,
            // A stale media reference resolves to null and renders as a
            // missing image, matching MediaLibrary's own contract — a badge
            // must still print when a logo was deleted.
            'logo' => $layout->logoMediaId === null
                ? null
                : $this->mediaLibrary->dataUriFor($layout->logoMediaId),
            'background' => $layout->backgroundMediaId === null
                ? null
                : $this->mediaLibrary->dataUriFor($layout->backgroundMediaId),
            'qr' => $layout->showQrCode && $token !== null ? $this->qrDataUri($token) : null,
        ])
            ->setPaper($paper, $layout->orientation)
            ->output();
    }

    /**
     * SVG rather than a raster backend: it needs no imagick or gd, stays
     * crisp at any print size, and dompdf renders inline SVG natively.
     */
    private function qrDataUri(string $token): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(320, 0), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($token));
    }
}
