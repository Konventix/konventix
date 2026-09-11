<?php

declare(strict_types=1);

use Konventix\Platform\Documents\DocumentWatermark;

/**
 * Spec §4.3: the ticket's watermark layers, built so the badge (#23) and
 * the invoice can adopt them later — this service never knows what a
 * holder or an event is, only the strings and the data URI it is handed.
 */
it('renders the forensic lines at 12% opacity, deterministically from the seed', function (): void {
    $watermark = app(DocumentWatermark::class);
    $lines = ['Jane Doe', 'Konventix Summit 2026', 'Participant', 'ADM-01K5'];

    $first = $watermark->html($lines, null, 'admission-01K5EXAMPLE');
    $second = $watermark->html($lines, null, 'admission-01K5EXAMPLE');

    expect($first)->toBe($second)
        ->and($first)->toContain('Jane Doe')
        ->and($first)->toContain('opacity:0.12')
        ->and($first)->not->toContain('<img');
});

it('changes the tile arrangement when the seed changes, but keeps the forensic text', function (): void {
    $watermark = app(DocumentWatermark::class);
    $lines = ['Jane Doe', 'Konventix Summit 2026'];

    $a = $watermark->html($lines, null, 'admission-one');
    $b = $watermark->html($lines, null, 'admission-two');

    expect($a)->not->toBe($b)
        ->and($a)->toContain('Jane Doe')
        ->and($b)->toContain('Jane Doe');
});

it('renders the brand mark when a logo is given and omits it, without failing, when there is none', function (): void {
    $watermark = app(DocumentWatermark::class);

    $withLogo = $watermark->html(['Jane Doe'], 'data:image/png;base64,AAAA', 'admission-01K5EXAMPLE');
    $withoutLogo = $watermark->html(['Jane Doe'], null, 'admission-01K5EXAMPLE');

    expect($withLogo)->toContain('<img src="data:image/png;base64,AAAA"')
        ->and($withoutLogo)->not->toContain('<img');
});

it('escapes forensic text so a holder name cannot inject markup', function (): void {
    $watermark = app(DocumentWatermark::class);

    $html = $watermark->html(['<script>alert(1)</script>'], null, 'admission-xss');

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;');
});
