<?php

declare(strict_types=1);

namespace Konventix\Platform\Documents;

/**
 * The watermark layers a ticket PDF needs (spec §4.3), built in Platform
 * rather than in Admission so the badge (#23) and the invoice can adopt the
 * same layers later — this service never knows what a holder, an event or
 * an admission is, only the strings and the data URI it is handed.
 *
 * Renders an HTML fragment for dompdf, not a full document: the consuming
 * view stacks this behind its own content. dompdf's CSS support is a
 * fraction of a browser's, so every layer here sticks to what it actually
 * renders correctly — absolute positioning, `transform: rotate`, opacity
 * and inline styles — and never flexbox, grid, or an external asset.
 * dompdf ships with remote fetching disabled, so a logo arrives here
 * already resolved to a `data:` URI by the caller (via
 * `MediaLibrary::dataUriFor()`), the same way `BadgeDocumentRenderer` and
 * `InvoiceDocumentRenderer` inline every image today.
 *
 * **No clear rectangle around the QR is computed here.** Spec §4.3 asks for
 * a clear zone so the forensic repeat never degrades a scan; that is a
 * document-composition concern, not a watermark one — only the consuming
 * view knows where the QR sits on its page. The view achieves it by
 * placing the QR's own container *after* this fragment's markup in the
 * page HTML and giving it an opaque background: dompdf paints absolutely
 * positioned boxes with no `z-index` in document order, the same as a
 * browser, so a later opaque box simply covers whatever this layer drew
 * underneath it. Getting that stacking order wrong is the ticket view's
 * bug to avoid, not this class's to prevent.
 */
class DocumentWatermark
{
    /**
     * A 4-column by 6-row grid is dense enough that no reasonably sized
     * forensic phrase list repeats in a way that looks empty, and sparse
     * enough that the page underneath stays legible through it.
     */
    private const int GRID_COLUMNS = 4;

    private const int GRID_ROWS = 6;

    /**
     * One fixed angle for every tile, not a per-tile random one: spec §4.3
     * calls for the repeat to run "diagonally across the page" — a single
     * consistent diagonal, the way a printed security pattern does, not a
     * scatter of angles.
     */
    private const float ROTATION_DEGREES = -28.0;

    /**
     * How far a tile may drift off its grid cell, in percentage points of
     * the page — enough that the rows do not read as a mechanical grid,
     * small enough that the text stays roughly where a forensic reader
     * expects it.
     */
    private const float JITTER_PERCENT = 6.0;

    /**
     * `$forensicLines` is a list of already-composed strings — this class
     * never knows a holder's name from an admission id, it only lays out
     * whatever strings it is handed. `$seed` drives every position and
     * rotation offset so that two renders of the same ticket (same seed,
     * same lines) are byte-identical; a re-download must never look like a
     * different, possibly forged, document.
     *
     * @param  list<string>  $forensicLines
     */
    public function html(array $forensicLines, ?string $brandLogoDataUri, string $seed): string
    {
        return $this->brandMarkLayer($brandLogoDataUri).$this->forensicLayer($forensicLines, $seed);
    }

    private function brandMarkLayer(?string $brandLogoDataUri): string
    {
        if ($brandLogoDataUri === null) {
            // The brand having no logo must still render a page (spec
            // §4.3: "Absent when the brand has no logo; the page must
            // still render"), so this layer degrades to nothing.
            return '';
        }

        return sprintf(
            '<div style="position:absolute; left:15%%; top:32%%; width:70%%; opacity:0.06; text-align:center;">'
            .'<img src="%s" style="width:100%%;" alt=""></div>',
            e($brandLogoDataUri),
        );
    }

    /**
     * @param  list<string>  $forensicLines
     */
    private function forensicLayer(array $forensicLines, string $seed): string
    {
        if ($forensicLines === []) {
            return '';
        }

        $tiles = '';
        $lineCount = count($forensicLines);

        for ($row = 0; $row < self::GRID_ROWS; $row++) {
            for ($column = 0; $column < self::GRID_COLUMNS; $column++) {
                $index = $row * self::GRID_COLUMNS + $column;
                $text = $forensicLines[$index % $lineCount];

                $left = $column * (100 / self::GRID_COLUMNS) + $this->seededJitter($seed, $index, 'x');
                $top = $row * (100 / self::GRID_ROWS) + $this->seededJitter($seed, $index, 'y');

                $tiles .= sprintf(
                    '<div style="position:absolute; left:%.2f%%; top:%.2f%%; width:60%%; '
                    .'opacity:0.12; transform:rotate(%.1fdeg); font-size:9pt; '
                    .'white-space:nowrap; color:#000000;">%s</div>',
                    $left,
                    $top,
                    self::ROTATION_DEGREES,
                    e($text),
                );
            }
        }

        return $tiles;
    }

    /**
     * A pure function of `$seed`, the tile index and the axis — never
     * `mt_rand()`/`random_int()`. This server is a long-running Octane
     * worker (CLAUDE.md): a source of randomness that touches PHP's
     * process-global PRNG state would perturb whatever the next request on
     * the same worker draws from it, for a watermark that has no need to
     * ever be unpredictable in the first place.
     */
    private function seededJitter(string $seed, int $index, string $axis): float
    {
        $fraction = crc32($seed.'|'.$axis.'|'.$index) / 4294967295;

        return ($fraction - 0.5) * self::JITTER_PERCENT;
    }
}
