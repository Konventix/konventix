<?php

declare(strict_types=1);

namespace Konventix\Platform\Media;

use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Generates the library's variants (ADR-0035 "generated variants") with
 * bare GD — deliberately no imaging dependency for a 480px thumbnail and
 * one cover-cropped strip; responsive variant sets can grow here later
 * behind the same variants map. Non-raster uploads (SVG, PDF) simply get
 * no variants.
 *
 * Two variants today, both webp, both cut from one decode of the original:
 *
 * - `thumb`: the original scaled to 480px wide, for pickers and previews.
 *   Skipped when the original is no wider than that.
 * - `ticket_banner`: the original cover-cropped to the ticket PDF's banner
 *   band (`admission::tickets.document` — the full 210mm page width by
 *   22mm) at up to 1240px wide. dompdf inlines every picture as a base64
 *   `data:` URI and crops with `background-size: cover` at render time, so
 *   without this strip every ticket page carried the whole original and
 *   threw most of it away. Never upscaled: a smaller original gets the
 *   largest band-shaped box that fits inside it, and one too small for
 *   even a one-pixel strip gets none — the renderer falls back to the
 *   original in that case, exactly as it does for a row uploaded before
 *   this variant existed.
 */
class ThumbnailGenerator
{
    private const int THUMB_WIDTH = 480;

    /** The 210mm band at 150 DPI, the ticket document's own (`TicketDocumentRenderer::DPI`). */
    public const int TICKET_BANNER_WIDTH = 1240;

    /**
     * The ticket's banner band in millimetres: the A4 page width (the band
     * is absolutely positioned across the whole page under `@page { margin:
     * 0 }`) by the band's own height. Both read off the Blade template —
     * change them together.
     */
    public const int TICKET_BANNER_BAND_WIDTH_MM = 210;

    public const int TICKET_BANNER_BAND_HEIGHT_MM = 22;

    private const array RASTER_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Takes a resolved filesystem rather than a disk name: media may live on
     * a brand's own bucket, which only `DiskResolver` knows how to build
     * (ADR-0043 point 6).
     *
     * @return array<string, string> variant name => storage path
     */
    public function generate(Filesystem $disk, string $path, string $mimeType): array
    {
        if (! in_array($mimeType, self::RASTER_MIMES, true)) {
            return [];
        }

        $source = imagecreatefromstring((string) $disk->get($path));

        if ($source === false) {
            return [];
        }

        $variants = [];

        foreach (['thumb' => $this->thumb($source), 'ticket_banner' => $this->ticketBanner($source)] as $name => $image) {
            if ($image === null) {
                continue;
            }

            $variantPath = self::variantPath($path, $name);
            $disk->put($variantPath, $this->webp($image));
            $variants[$name] = $variantPath;
        }

        imagedestroy($source);

        return $variants;
    }

    private function thumb(GdImage $source): ?GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= self::THUMB_WIDTH) {
            return null;
        }

        $thumbWidth = self::THUMB_WIDTH;
        $thumbHeight = max(1, (int) round($height * ($thumbWidth / $width)));

        return $this->resample($source, $thumbWidth, $thumbHeight, 0, 0, $width, $height);
    }

    /**
     * The same crop `background-size: cover; background-position: center`
     * makes: scale the original until it fills the band in both directions,
     * then keep the centred window that lands inside it.
     */
    private function ticketBanner(GdImage $source): ?GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = self::TICKET_BANNER_BAND_WIDTH_MM / self::TICKET_BANNER_BAND_HEIGHT_MM;

        // The largest band-shaped box that fits inside the original, capped
        // at the target width — so nothing is ever upscaled.
        $targetWidth = (int) min(self::TICKET_BANNER_WIDTH, $width, floor($height * $ratio));
        $targetHeight = (int) round($targetWidth / $ratio);

        if ($targetWidth < 1 || $targetHeight < 1) {
            return null;
        }

        $scale = max($targetWidth / $width, $targetHeight / $height);
        $cropWidth = min($width, (int) round($targetWidth / $scale));
        $cropHeight = min($height, (int) round($targetHeight / $scale));

        return $this->resample(
            $source,
            $targetWidth,
            $targetHeight,
            intdiv($width - $cropWidth, 2),
            intdiv($height - $cropHeight, 2),
            $cropWidth,
            $cropHeight,
        );
    }

    /**
     * @param  positive-int  $width
     * @param  positive-int  $height
     */
    private function resample(GdImage $source, int $width, int $height, int $sourceX, int $sourceY, int $sourceWidth, int $sourceHeight): GdImage
    {
        $target = imagecreatetruecolor($width, $height);
        // Keep PNG/GIF/WebP transparency instead of a black matte.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, $sourceX, $sourceY, $width, $height, $sourceWidth, $sourceHeight);

        return $target;
    }

    private function webp(GdImage $image): string
    {
        ob_start();
        imagewebp($image, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private static function variantPath(string $path, string $variant): string
    {
        return preg_replace('/(\.[^.\/]+)?$/', '', $path, 1).'.'.$variant.'.webp';
    }
}
