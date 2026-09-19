<?php

declare(strict_types=1);

namespace Konventix\Admission\Documents;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The scan code as an image, in the two forms its two readers need (#292
 * §4.4): inline SVG markup for the ticket page's DOM, and a `data:` URI
 * for dompdf, which ships with no network access and needs every image
 * inlined. Both encode the same string — the admission's current KVX1
 * token (ADR-0061) — so a code scanned off a phone screen and one scanned
 * off paper are the same credential.
 */
final class TicketQr
{
    public const int SIZE_PX = 320;

    public function svg(string $token): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::SIZE_PX, 0), new SvgImageBackEnd));

        // The XML declaration is for a file, not for markup inlined into
        // an HTML document; dropped so `v-html` gets a bare `<svg>`.
        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $writer->writeString($token));
    }

    public function dataUri(string $token): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::SIZE_PX, 0), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($token));
    }
}
