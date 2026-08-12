<?php

declare(strict_types=1);

namespace Konventix\Platform\Media;

use Illuminate\Support\Facades\Storage;

/**
 * Generates the library's `thumb` variant (ADR-0035 "generated variants")
 * with bare GD — deliberately no imaging dependency for a 480px thumbnail;
 * responsive variant sets can grow here later behind the same variants
 * map. Non-raster uploads (SVG, PDF) simply get no variants.
 */
class ThumbnailGenerator
{
    private const int THUMB_WIDTH = 480;

    private const array RASTER_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * @return array<string, string> variant name => storage path
     */
    public function generate(string $disk, string $path, string $mimeType): array
    {
        if (! in_array($mimeType, self::RASTER_MIMES, true)) {
            return [];
        }

        $source = imagecreatefromstring(Storage::disk($disk)->get($path));

        if ($source === false) {
            return [];
        }

        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= self::THUMB_WIDTH) {
            imagedestroy($source);

            return [];
        }

        $thumbWidth = self::THUMB_WIDTH;
        $thumbHeight = max(1, (int) round($height * ($thumbWidth / $width)));

        $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
        // Keep PNG/GIF/WebP transparency instead of a black matte.
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        imagewebp($thumb, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($thumb);

        $thumbPath = preg_replace('/(\.[^.\/]+)?$/', '', $path, 1).'.thumb.webp';

        Storage::disk($disk)->put($thumbPath, $bytes);

        return ['thumb' => $thumbPath];
    }
}
