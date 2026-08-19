<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\StoreMediaUpload;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;

/**
 * The brand's slice of the media library (ADR-0035): placeholder images
 * pushed through the real StoreMediaUpload action, so the demo carries
 * stored files, generated `thumb` variants and rows the operator console
 * can list, search and refuse to delete.
 *
 * Keyed by purpose — the hero lands in the home page's hero block and the
 * logo/background in every event's badge template, which is what gives
 * the library non-zero usage references to show; the sponsor logos stay
 * unreferenced so the deletable case exists too.
 *
 * Idempotent per file rather than per brand: an item is looked up by its
 * filename and only uploaded when missing, so re-runs — and databases
 * seeded before this slice existed — top up instead of duplicating.
 */
final class DemoMediaSeeder
{
    /**
     * Background colours for generated placeholder media, picked per file so
     * a brand's library is visually distinguishable in the console grid.
     *
     * @var list<array{int, int, int}>
     */
    private const array PALETTE = [
        [37, 99, 235],
        [217, 70, 239],
        [16, 185, 129],
        [234, 88, 12],
        [99, 102, 241],
        [14, 165, 233],
    ];

    public function __construct(private readonly StoreMediaUpload $store) {}

    /**
     * @return array<string, MediaItem>
     */
    public function handle(Brand $brand): array
    {
        $slug = Str::slug($brand->name);

        /** @var array<string, array{string, int, int, string, string}> $definitions */
        $definitions = [
            'logo' => ["{$slug}-logo.png", 640, 640, $brand->name, "{$brand->name} logo"],
            'hero' => ["{$slug}-hero.jpg", 1600, 900, $brand->name, "The {$brand->name} main hall"],
            'badge-background' => ["{$slug}-badge-background.png", 880, 1240, $brand->name, "{$brand->name} badge background"],
            'sponsor-northwind' => ['sponsor-northwind.png', 720, 360, 'Northwind', 'Northwind Systems logo'],
            'sponsor-hexatech' => ['sponsor-hexatech.png', 720, 360, 'Hexatech', 'Hexatech logo'],
        ];

        // Uploads are attributed to the operator admin the console logs in
        // as; the column is nullable, so a database without it is fine.
        $uploader = User::query()->firstWhere('email', 'admin@konventix.test');

        $media = [];

        foreach ($definitions as $key => [$filename, $width, $height, $label, $altText]) {
            // Brand context is set: the scope constrains the lookup (ADR-0010).
            $existing = MediaItem::query()->firstWhere('original_filename', $filename);

            if ($existing !== null) {
                $media[$key] = $existing;

                continue;
            }

            $upload = $this->placeholderUpload($filename, $width, $height, $label);

            // Explicit brand ULID, not ambient context: uploads normally
            // arrive on the brand-less operator host (ADR-0029).
            $media[$key] = $this->store->handle(
                brandId: $brand->getKey(),
                file: $upload,
                altText: $altText,
                uploadedByUserId: $uploader?->getKey(),
            );

            @unlink($upload->getPathname());
        }

        return $media;
    }

    /**
     * A generated placeholder image on disk, wrapped as an `UploadedFile` so
     * it travels the production upload path untouched — which is also what
     * produces the `thumb` variant. Drawn with bare GD, the same dependency
     * ThumbnailGenerator already assumes, so no demo-only package appears.
     */
    private function placeholderUpload(string $filename, int $width, int $height, string $label): UploadedFile
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));

        [$red, $green, $blue] = self::PALETTE[abs(crc32($filename)) % count(self::PALETTE)];

        imagefilledrectangle($image, 0, 0, $width, $height, $this->allocate($image, $red, $green, $blue));

        // A darker footer band: flat colour swatches are hard to tell apart
        // once the console scales them down to thumbnails.
        imagefilledrectangle(
            $image,
            0,
            (int) ($height * 0.78),
            $width,
            $height,
            $this->allocate($image, (int) ($red * 0.6), (int) ($green * 0.6), (int) ($blue * 0.6)),
        );

        $this->stampLabel($image, $label, $width, (int) ($height * 0.78), $red, $green, $blue);

        $path = (string) tempnam(sys_get_temp_dir(), 'konventix-media-');

        if (str_ends_with($filename, '.jpg')) {
            imagejpeg($image, $path, 82);
        } else {
            imagepng($image, $path);
        }

        // No imagedestroy(): deprecated as a no-op since PHP 8.0, and the
        // GdImage goes out of scope here anyway.

        // `test: true` skips the is_uploaded_file() check — the file comes
        // from this process, not from a POST.
        return new UploadedFile($path, $filename, test: true);
    }

    /**
     * Centres a label in the image's upper region. GD's built-in fonts top
     * out at a few pixels, so the text is drawn once at font size on a canvas
     * matching the background and then scaled up — big readable type with no
     * font file to ship and no visible seam against the background.
     */
    private function stampLabel(GdImage $image, string $label, int $width, int $height, int $red, int $green, int $blue): void
    {
        $label = strtoupper(mb_substr($label, 0, 20));

        $textWidth = max(1, imagefontwidth(5) * mb_strlen($label));
        $textHeight = max(1, imagefontheight(5));

        $canvas = imagecreatetruecolor($textWidth, $textHeight);
        imagefilledrectangle($canvas, 0, 0, $textWidth, $textHeight, $this->allocate($canvas, $red, $green, $blue));
        imagestring($canvas, 5, 0, 0, $label, $this->allocate($canvas, 255, 255, 255));

        $scale = max(2, (int) floor(($width * 0.7) / $textWidth));
        $scaledWidth = $textWidth * $scale;
        $scaledHeight = $textHeight * $scale;

        imagecopyresampled(
            $image,
            $canvas,
            (int) (($width - $scaledWidth) / 2),
            (int) (($height - $scaledHeight) / 2),
            0,
            0,
            $scaledWidth,
            $scaledHeight,
            $textWidth,
            $textHeight,
        );
    }

    /**
     * A palette entry as a GD colour index. Channels are clamped rather than
     * trusted (the footer band scales them down), and an exhausted palette —
     * `imagecolorallocate()` returning false — falls back to index 0 so a
     * placeholder image is never worth failing the whole seed over.
     */
    private function allocate(GdImage $image, int $red, int $green, int $blue): int
    {
        $color = imagecolorallocate(
            $image,
            max(0, min(255, $red)),
            max(0, min(255, $green)),
            max(0, min(255, $blue)),
        );

        return $color === false ? 0 : $color;
    }
}
